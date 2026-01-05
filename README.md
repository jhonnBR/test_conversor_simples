# 💱 Dashboard de Cotações Pro

Uma aplicação moderna e robusta para monitoramento de câmbio em tempo real, construída com foco em performance e segurança utilizando as features mais recentes do **PHP 8.5.1**.

## 🚀 Principais Funcionalidades

- **Dashboard Real-time**: Visualização elegante das principais moedas (USD, EUR, GBP, etc.) em relação ao Real (BRL).
- **Calculadora Conversora**: Conversão instantânea de valores entre qualquer moeda suportada com lógica *client-side* para melhor UX.
- **Cache Inteligente com SQLite**: Persistência local de dados para evitar requisições desnecessárias e garantir carregamento instantâneo.
- **Sistema de Cooldown**: Trava de segurança de 1 minuto para atualizações manuais, prevenindo o excesso de uso da quota da API.
- **Arquitetura MVC**: Código modular e organizado seguindo padrões de projeto (Model-View-Controller).
- **Segurança (.env)**: Proteção de chaves sensíveis através de variáveis de ambiente.

## 🛠️ Tecnologias Utilizadas

- **Backend**: PHP 8.5.1 (Enums, Readonly Classes, Constructor Promotion, Named Arguments).
- **Banco de Dados**: SQLite3 (via PDO).
- **Frontend**: Vanilla JS (ES6+), CSS3 (Modern UI c/ Dark Mode).
- **API Externa**: [AwesomeAPI](https://docs.awesomeapi.com.br/) (Câmbio de Moedas).

## 📋 Pré-requisitos

- PHP 8.5.1 ou superior.
- Extensões PHP habilitadas: `curl`, `pdo_sqlite`, `openssl`.

## 🔧 Instalação e Configuração

1. Clone o repositório para o seu ambiente local (ex: Laragon, XAMPP).
2. Crie um arquivo chamado `.env.local` na raiz do projeto.
3. Adicione sua chave de API no arquivo `.env.local`:
   ```env
   API_KEY = sua_chave_aqui
   ```
4. Configure o seu servidor web para apontar para a raiz do projeto.
5. Acesse via navegador (ex: `http://localhost/cotacao`).

## 📁 Estrutura do Projeto

```text
├── .env.local          # Chaves sensíveis (não versionado)
├── .gitignore          # Regras de exclusão do Git
├── database.sqlite     # Banco de dados local (não versionado)
├── index.php           # Aplicação principal (MVC Unificado)
└── README.md           # Documentação do projeto
```

## 📄 Licença

Este projeto é de uso livre para estudos e desenvolvimento pessoal.

---
Dados fornecidos por [AwesomeAPI](https://docs.awesomeapi.com.br/).
