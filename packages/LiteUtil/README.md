# LiteUtil 🛠️

[![Latest Version](https://img.shields.io/github/v/release/kzxl/LiteUtil?label=version&color=blue)](https://github.com/kzxl/LiteUtil/releases)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Lightweight, high-performance PHP 8.2+ utility suite: multilingual number-to-words, zero-regex diacritics removal, string helpers, and enterprise master data validators.

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Zero External Dependencies](https://img.shields.io/badge/Dependencies-0-brightgreen.svg)]()
[![Tests](https://img.shields.io/badge/Tests-19%2F19%20Pass%20(100%25)-brightgreen.svg)]()

---

## ✨ Features

| Feature Area | Class | Capabilities |
| :--- | :--- | :--- |
| **Multilingual Number & Currency** | `NumberToWords` | Converts numbers and monetary amounts to words in Vietnamese (`vi`) and English (`en`). Extensible registry for custom languages. |
| **Fast Diacritics & Slugs** | `Str` | Zero-regex accent removal via $O(1)$ lookup table (`DiacriticsMap`). SEO URL slugifier, camelCase, snake_case, kebab-case, mask, truncate. |
| **Vietnam Master Data** | `VnMasterData` | Circular 105/2020/TT-BTC Tax Code validator (Modulo 11 check digit), Ministry of Public Security C06 12-digit Citizen ID decoder (`CccdInfo`), and mobile phone normalizer. |

---

## 📦 Installation

### Option 1: Standard Composer (via Packagist)
```bash
composer require kzxl/lite-util
```

### Option 2: Direct from Git Repository (VCS)
To pull directly from the official GitHub repository without waiting for Packagist synchronization, add the VCS repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/kzxl/LiteUtil.git"
        }
    ],
    "require": {
        "kzxl/lite-util": "^1.1.0"
    }
}
```
Or configure via CLI:
```bash
composer config repositories.lite-util vcs https://github.com/kzxl/LiteUtil.git
composer require kzxl/lite-util:^1.1.0
```

### Option 3: Local Path Repository (Monorepo / Development)
For local development where changes should reflect immediately via symlink:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../libs/LiteUtil",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "kzxl/lite-util": "@dev"
    }
}
```

---

## 🚀 Usage Guide

### 1. Multilingual Number & Currency to Words

```php
use LiteUtil\Number\NumberToWords;

// Vietnamese Numbers
NumberToWords::toWords(1250000, 'vi');
// => "một triệu hai trăm năm mươi nghìn"

NumberToWords::toWords(1005, 'vi');
// => "một nghìn không trăm lẻ năm"

// Vietnamese Currency (VND, USD)
NumberToWords::toCurrency(1250000, 'VND', 'vi');
// => "một triệu hai trăm năm mươi nghìn đồng chẵn"

NumberToWords::toCurrency(100.50, 'USD', 'vi');
// => "một trăm đô la Mỹ và năm mươi cent"

// English Numbers & Currency
NumberToWords::toWords(1250000, 'en');
// => "one million two hundred fifty thousand"

NumberToWords::toCurrency(1250.50, 'USD', 'en');
// => "one thousand two hundred fifty dollars and fifty cents"

NumberToWords::toCurrency(50.20, 'EUR', 'en');
// => "fifty euros and twenty cents"
```

#### Register Custom Language Formatter
```php
use LiteUtil\Number\NumberToWords;
use LiteUtil\Number\LanguageFormatterInterface;

NumberToWords::registerFormatter('fr', new class implements LanguageFormatterInterface {
    public function formatNumber(int|float|string $number): string {
        return "un million"; // French formatting logic
    }
    public function formatCurrency(int|float|string $amount, string $currency = 'EUR', bool $subUnits = true): string {
        return "un million d'euros";
    }
});
```

---

### 2. High-Performance String & Slug Utilities

```php
use LiteUtil\Text\Str;

// Fast Diacritics Removal (Zero Regex, O(1) character translation)
Str::removeAccents('Học lập trình PHP tại Việt Nam & café');
// => "Hoc lap trinh PHP tai Viet Nam & cafe"

// SEO URL Slugifier
Str::slug('Hướng Dẫn Lập Trình PHP 8.2 & Modern Features!');
// => "huong-dan-lap-trinh-php-8-2-modern-features"

// Case Transformations
Str::camel('order_item_detail'); // "orderItemDetail"
Str::pascal('order_item_detail'); // "OrderItemDetail"
Str::snake('OrderItemDetail');   // "order_item_detail"
Str::kebab('OrderItemDetail');   // "order-item-detail"

// Data Masking (GDPR & Security)
Str::mask('0912345678', '*', 3, 4); // "091****678"
Str::mask('phong@example.com', '*', 1, 4); // "p****@example.com"
```

---

### 3. Enterprise Vietnamese Master Data Validators

```php
use LiteUtil\Validation\VnMasterData;

// 1. Tax Identification Number (MST)
VnMasterData::isValidMst('0100109106'); // true (valid Modulo 11 check digit)
VnMasterData::isValidMst('0100109106-001'); // true (valid 13-digit branch MST)
VnMasterData::normalizeMst('0100109106001'); // "0100109106-001"

// 2. Citizen Identity Card (CCCD) Decode
$info = VnMasterData::decodeCccd('001095012345');
// $info->provinceName => "Hà Nội"
// $info->birthYear    => 1995
// $info->isMale       => true
// $info->getGender()  => "Nam"

// 3. Mobile Phone Normalization (03x, 05x, 07x, 08x, 09x)
VnMasterData::normalizePhone('+84 90 123 4567'); // "0901234567"
VnMasterData::normalizePhone('0901234567', international: true); // "+84901234567"
```

---

## 🧪 Testing

```bash
composer test
# or
./vendor/bin/phpunit
```

---

## 📄 License

Released under the **MIT License**. See [LICENSE](LICENSE) for details.  
Architected by **Phong Vo** (`kzxl`).

