<?php

declare(strict_types=1);

/**
 * Super Dashboard de Cotações & Conversor c/ Cache SQLite - PHP 8.5.1
 * 
 * @version 4.0.0
 * @requires PHP 8.5.0+ (ext-pdo_sqlite habilitada)
 */

if (version_compare(PHP_VERSION, '8.5.0', '<')) {
    exit('Considere atualizar para o PHP 8.5.0+');
}

// ============================================================================
// CONFIGURAÇÃO & AMBIENTE
// ============================================================================

/**
 * Carrega variáveis de ambiente de um arquivo .env simples
 */
function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

loadEnv(__DIR__ . '/.env.local');

define('API_KEY', $_ENV['API_KEY'] ?? '');
const DB_PATH = __DIR__ . '/database.sqlite';

// ============================================================================
// MODELS & ENUMS
// ============================================================================

enum Currency: string
{
    case BRL = 'BRL';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case JPY = 'JPY';
    case CAD = 'CAD';
    case AUD = 'AUD';
    case CHF = 'CHF';
    case CNY = 'CNY';

    public function label(): string {
        return match($this) {
            self::BRL => 'Real Brasileiro',
            self::USD => 'Dólar Americano',
            self::EUR => 'Euro',
            self::GBP => 'Libra Esterlina',
            self::JPY => 'Iene Japonês',
            self::CAD => 'Dólar Canadense',
            self::AUD => 'Dólar Australiano',
            self::CHF => 'Franco Suíço',
            self::CNY => 'Yuan Chinês',
        };
    }

    public function flag(): string {
        return match($this) {
            self::BRL => '🇧🇷', self::USD => '🇺🇸', self::EUR => '🇪🇺',
            self::GBP => '🇬🇧', self::JPY => '🇯🇵', self::CAD => '🇨🇦',
            self::AUD => '🇦🇺', self::CHF => '🇨🇭', self::CNY => '🇨🇳',
        };
    }

    public function symbol(): string {
        return match($this) {
            self::BRL => 'R$', self::USD => '$', self::EUR => '€',
            self::GBP => '£', self::JPY => '¥', self::CAD => 'C$',
            self::AUD => 'A$', self::CHF => 'CHF', self::CNY => '¥',
        };
    }

    public static function fiduciaries(): array {
        return array_filter(self::cases(), fn($c) => $c !== self::BRL);
    }
}

readonly class Quote
{
    public function __construct(
        public Currency $currency,
        public float $bid,
        public float $ask,
        public float $pctChange,
        public string $timestamp,
        public string $lastSync
    ) {}

    public function formattedBid(): string {
        return 'R$ ' . number_format($this->bid, 4, ',', '.');
    }
}

// ============================================================================
// DATABASE SERVICE (SQLite)
// ============================================================================

final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO("sqlite:$path");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->init();
    }

    private function init(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS quotes (
                code TEXT PRIMARY KEY,
                bid REAL,
                ask REAL,
                pct_change REAL,
                api_timestamp TEXT,
                last_sync DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function saveQuotes(array $quotes): void
    {
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO quotes (code, bid, ask, pct_change, api_timestamp, last_sync)
            VALUES (:code, :bid, :ask, :pct_change, :api_timestamp, CURRENT_TIMESTAMP)
        ");

        foreach ($quotes as $q) {
            $stmt->execute([
                ':code' => $q->currency->value,
                ':bid' => $q->bid,
                ':ask' => $q->ask,
                ':pct_change' => $q->pctChange,
                ':api_timestamp' => $q->timestamp
            ]);
        }
    }

    public function getQuotes(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM quotes");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $quotes = [];
        foreach ($rows as $row) {
            $currency = Currency::tryFrom($row['code']);
            if ($currency) {
                $quotes[] = new Quote(
                    currency: $currency,
                    bid: (float) $row['bid'],
                    ask: (float) $row['ask'],
                    pctChange: (float) $row['pct_change'],
                    timestamp: $row['api_timestamp'],
                    lastSync: $row['last_sync']
                );
            }
        }
        return $quotes;
    }
}

// ============================================================================
// API SERVICE
// ============================================================================

final class ApiClient
{
    private const string BASE_URL = 'https://economia.awesomeapi.com.br/json/last/';

    public function __construct(private readonly string $apiKey = '') {}

    public function fetchLatest(): array
    {
        $pairs = array_map(fn($c) => $c->value . '-BRL', Currency::fiduciaries());
        $url = self::BASE_URL . implode(',', $pairs) . ($this->apiKey ? "?token={$this->apiKey}" : "");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 PHP/' . PHP_VERSION
        ]);
        $res = curl_exec($ch);
        
        if (!$res) return [];
        $decoded = json_decode((string)$res, true);
        if (!is_array($decoded) || isset($decoded['status'])) return [];

        $quotes = [];
        foreach ($decoded as $key => $val) {
            $code = str_replace('BRL', '', $key);
            $currency = Currency::tryFrom($code);
            if ($currency) {
                $quotes[] = new Quote(
                    currency: $currency,
                    bid: (float) $val['bid'],
                    ask: (float) $val['ask'],
                    pctChange: (float) $val['pctChange'],
                    timestamp: $val['create_date'] ?? date('Y-m-d H:i:s'),
                    lastSync: date('Y-m-d H:i:s')
                );
            }
        }
        return $quotes;
    }
}

// ============================================================================
// CONTROLLER
// ============================================================================

$db = new Database(DB_PATH);
$api = new ApiClient(API_KEY);
$quotes = $db->getQuotes();

// Lógica de Cooldown (1 minuto)
$canRefresh = true;
$secondsRemaining = 0;
$cooldownTime = 60; // segundos

if (!empty($quotes)) {
    $lastSync = strtotime($quotes[0]->lastSync);
    $timePassed = time() - $lastSync;
    
    if ($timePassed < $cooldownTime) {
        $canRefresh = false;
        $secondsRemaining = $cooldownTime - $timePassed;
    }
}

// Lógica de Atualização Forçada
if (isset($_GET['refresh']) && $canRefresh) {
    $freshQuotes = $api->fetchLatest();
    if (!empty($freshQuotes)) {
        $db->saveQuotes($freshQuotes);
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// Se o banco estiver vazio, faz sync inicial
if (empty($quotes)) {
    $quotes = $api->fetchLatest();
    $db->saveQuotes($quotes);
}

// Formata para JS
$rawRates = ['BRL' => 1.0];
$lastSyncTime = !empty($quotes) ? $quotes[0]->lastSync : 'N/A';
foreach ($quotes as $q) {
    $rawRates[$q->currency->value] = $q->bid;
}

// ============================================================================
// VIEW (HTML/CSS/JS)
// ============================================================================
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotações v4 | SQLite Cache</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #050505; --card: #0f0f0f; --accent: #3b82f6; --text: #f0f0f0; --muted: #666;
            --pos: #22c55e; --neg: #ef4444; --border: #1a1a1a;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Outfit', sans-serif; background: var(--bg); color: var(--text); padding: 2rem; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        header { 
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 2.5rem; border-bottom: 1px solid var(--border); padding-bottom: 1.5rem;
        }
        .header-info h1 { font-size: 1.8rem; letter-spacing: -0.01em; }
        .sync-badge { font-size: 0.75rem; color: var(--muted); margin-top: 0.3rem; }

        .btn-refresh {
            background: var(--accent); color: white; border: none; padding: 0.7rem 1.2rem;
            border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-refresh.disabled {
            background: #222; color: var(--muted); cursor: not-allowed; border: 1px solid var(--border);
        }
        .btn-refresh:hover:not(.disabled) { opacity: 0.9; transform: translateY(-1px); }

        .layout { display: grid; grid-template-columns: 1fr 320px; gap: 2rem; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; }
        
        footer {
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: 0.8rem;
            color: var(--muted);
        }
        footer a { color: var(--accent); text-decoration: none; font-weight: 600; }
        footer a:hover { text-decoration: underline; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 0.75rem; color: var(--muted); text-transform: uppercase; padding: 1rem; border-bottom: 1px solid var(--border); }
        td { padding: 1.2rem 1rem; border-bottom: 1px solid var(--border); }
        .currency-cell { display: flex; align-items: center; gap: 1rem; }
        .code { font-weight: 700; color: var(--accent); }
        .price { font-family: 'JetBrains Mono', monospace; font-size: 1.1rem; }
        .var { font-weight: 600; }
        .positive { color: var(--pos); } .negative { color: var(--neg); }

        /* Conversor */
        .converter h3 { margin-bottom: 1.5rem; font-size: 1.1rem; }
        .input-group { margin-bottom: 1.2rem; }
        label { display: block; font-size: 0.7rem; color: var(--muted); text-transform: uppercase; margin-bottom: 0.4rem; font-weight: 600; }
        .input-field { 
            width: 100%; background: #1a1a1a; border: 1px solid var(--border); 
            padding: 0.8rem; color: white; border-radius: 6px; font-family: inherit;
        }
        .res-box { margin-top: 1.5rem; padding: 1.5rem; background: #000; border-radius: 8px; text-align: center; }
        #res_total { font-family: 'JetBrains Mono', monospace; font-size: 1.8rem; color: var(--pos); font-weight: 700; }
        
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-info">
                <h1>Cotações Dashboard</h1>
                <div class="sync-badge">Sincronizado via SQLite: <?= date('d/m/Y H:i:s', strtotime($lastSyncTime)) ?></div>
            </div>
            <?php if ($canRefresh): ?>
                <a href="?refresh=1" class="btn-refresh">🔄 Atualizar agora</a>
            <?php else: ?>
                <button class="btn-refresh disabled" id="refresh-timer" disabled>
                    ⏳ Aguarde <span id="secs"><?= $secondsRemaining ?></span>s
                </button>
            <?php endif; ?>
        </header>

        <main class="layout">
            <section class="card">
                <table>
                    <thead>
                        <tr><th>Moeda</th><th>Cotação</th><th>Variação</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotes as $q): ?>
                        <tr>
                            <td>
                                <div class="currency-cell">
                                    <span><?= $q->currency->flag() ?></span>
                                    <div><div class="code"><?= $q->currency->value ?></div></div>
                                </div>
                            </td>
                            <td class="price"><?= $q->formattedBid() ?></td>
                            <td class="var <?= $q->pctChange >= 0 ? 'positive' : 'negative' ?>">
                                <?= $q->pctChange >= 0 ? '↑' : '↓' ?> <?= abs($q->pctChange) ?>%
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <aside class="card converter">
                <h3>Calculadora</h3>
                <div class="input-group">
                    <label>Valor</label>
                    <input type="number" id="amt" class="input-field" value="1.00">
                </div>
                <div class="input-group">
                    <label>De</label>
                    <select id="from" class="input-field">
                        <?php foreach (Currency::cases() as $c): ?>
                            <option value="<?= $c->value ?>" <?= $c==Currency::USD?'selected':'' ?>><?= $c->flag() ?> <?= $c->value ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Para</label>
                    <select id="to" class="input-field">
                        <?php foreach (Currency::cases() as $c): ?>
                            <option value="<?= $c->value ?>" <?= $c==Currency::BRL?'selected':'' ?>><?= $c->flag() ?> <?= $c->value ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="res-box">
                    <div id="res_total">R$ 0,00</div>
                </div>
            </aside>
        </main>

        <footer>
            Dados fornecidos em tempo real por <a href="https://docs.awesomeapi.com.br/" target="_blank">AwesomeAPI</a> • PHP 8.5.1
        </footer>
    </div>

    <script>
        const rates = <?= json_encode($rawRates) ?>;
        const amt = document.getElementById('amt');
        const from = document.getElementById('from');
        const to = document.getElementById('to');
        const res = document.getElementById('res_total');

        function calc() {
            const v = parseFloat(amt.value) || 0;
            const r = (rates[from.value] || 1) / (rates[to.value] || 1);
            res.innerText = (v * r).toLocaleString('pt-BR', {style: 'currency', currency: to.value});
        }
        [amt, from, to].forEach(el => el.oninput = calc);
        calc();

        // Timer de Cooldown
        const timerSpan = document.getElementById('secs');
        if (timerSpan) {
            let seconds = parseInt(timerSpan.innerText);
            const interval = setInterval(() => {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(interval);
                    location.reload(); // Recarrega para habilitar o botão
                }
                timerSpan.innerText = seconds;
            }, 1000);
        }
    </script>
</body>
</html>