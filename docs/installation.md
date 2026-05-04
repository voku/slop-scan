# Installation

## Requirements

- PHP 8.3+
- Composer

## Install the latest PHAR

```bash
mkdir -p "$HOME/.local/bin"
curl -fsSL https://github.com/voku/slop-scan/releases/latest/download/slop-scan.phar -o "$HOME/.local/bin/slop-scan"
chmod +x "$HOME/.local/bin/slop-scan"
"$HOME/.local/bin/slop-scan" scan .
```

## Install dependencies for local development

```bash
composer install
```

Run the CLI from the repository checkout:

```bash
php bin/slop-scan.php scan .
```

## Build a PHAR locally

```bash
composer run phar:build
php dist/slop-scan.phar scan .
```
