<?php

declare(strict_types=1);

namespace LiteValidate\I18n;

/**
 * Lightweight multi-language translation engine for validation error messages.
 */
class Translator
{
    private static string $locale = 'en';

    /** @var array<string, array<string, string>> */
    private static array $messages = [
        'en' => [
            'required' => 'The {field} field is required.',
            'email' => 'The {field} field must be a valid email address.',
            'url' => 'The {field} field must be a valid URL.',
            'regex' => 'The {field} field format is invalid.',
            'in' => 'The selected {field} is invalid.',
            'length.min' => 'The {field} field must be at least {min} characters.',
            'length.max' => 'The {field} field must not exceed {max} characters.',
            'numeric' => 'The {field} field must be numeric.',
            'positive' => 'The {field} field must be greater than 0.',
            'positive_or_zero' => 'The {field} field must be greater than or equal to 0.',
            'range.min' => 'The {field} field must be at least {min}.',
            'range.max' => 'The {field} field must not exceed {max}.',
            'required_if' => 'The {field} field is required when {other} is {value}.',
            'unique' => 'The {field} has already been taken.',
            'exists' => 'The selected {field} does not exist.',
        ],
        'vi' => [
            'required' => 'Trường {field} không được để trống.',
            'email' => 'Trường {field} phải là một địa chỉ email hợp lệ.',
            'url' => 'Trường {field} phải là một đường dẫn URL hợp lệ.',
            'regex' => 'Trường {field} có định dạng không hợp lệ.',
            'in' => 'Giá trị đã chọn cho {field} không hợp lệ.',
            'length.min' => 'Trường {field} phải có độ dài tối thiểu {min} ký tự.',
            'length.max' => 'Trường {field} không được vượt quá {max} ký tự.',
            'numeric' => 'Trường {field} phải là một số hợp lệ.',
            'positive' => 'Trường {field} phải lớn hơn 0.',
            'positive_or_zero' => 'Trường {field} phải lớn hơn hoặc bằng 0.',
            'range.min' => 'Trường {field} phải có giá trị tối thiểu là {min}.',
            'range.max' => 'Trường {field} không được vượt quá {max}.',
            'required_if' => 'Trường {field} là bắt buộc khi {other} là {value}.',
            'unique' => '{field} này đã tồn tại trong hệ thống.',
            'exists' => '{field} đã chọn không tồn tại.',
        ],
    ];

    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    public static function trans(string $key, array $params = [], ?string $customMessage = null): string
    {
        if ($customMessage !== null) {
            return self::interpolate($customMessage, $params);
        }

        $locale = self::$locale;
        $template = self::$messages[$locale][$key]
            ?? self::$messages['en'][$key]
            ?? "Validation error on {field}";

        return self::interpolate($template, $params);
    }

    public static function addMessages(string $locale, array $messages): void
    {
        self::$messages[$locale] = array_merge(self::$messages[$locale] ?? [], $messages);
    }

    private static function interpolate(string $template, array $params): string
    {
        foreach ($params as $k => $v) {
            $template = str_replace('{' . $k . '}', (string) $v, $template);
        }
        return $template;
    }
}
