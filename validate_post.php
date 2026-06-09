<?php


/**
 * Проверяет пользовательский пост на соответствие условиям задания.
 *
 * Основная идея:
 * - регулярными выражениями проверяется форма тегов, атрибутов и XML-сущностей;
 * - обычной логикой со стеком проверяются закрывающие теги и вложенность;
 * - DOM, SimpleXML, XMLReader и похожие встроенные парсеры не используются.
 */
function validatePost(mixed $post): bool
{
    // возвращает boolean, поэтому некорректный тип сразу невалиден.
    if (!is_string($post)) {
        return false;
    }

    // Проверяем, XHTML является xml-документом или фрагментом, а значит текст должен быть валидным utf-8.
    if (!preg_match('//u', $post)) {
        return false;
    }

    // Полный список разрешенных тегов и атрибутов.
    // Все остальные теги и атрибуты будут отклонены в parseTag().
    $allowedTags = [
        'a' => ['href', 'title'],
        'code' => [],
        'i' => [],
        'strike' => [],
        'strong' => [],
    ];

    $stack = [];
    $length = strlen($post);
    $position = 0;

    // Текстовые фрагменты проверяются как xml-текст, а найденные теги - как XHTML-теги.
    while ($position < $length) {
        $tagStart = strpos($post, '<', $position);

        if ($tagStart === false) {
            // Пункт 3: в конце поста стек должен быть пустым, иначе остались незакрытые теги.
            return validateXmlText(substr($post, $position)) && empty($stack);
        }

        // Тут проверяем, что текст между тегами не должен содержать неэкранированный &,
        // запрещенные xml-символы или закрытие CDATA.
        if (!validateXmlText(substr($post, $position, $tagStart - $position))) {
            return false;
        }

        // ищем конец тега с учетом кавычек внутри атрибутов.
        $tagEnd = findTagEnd($post, $tagStart);
        if ($tagEnd === false) {
            return false;
        }

        $tag = substr($post, $tagStart, $tagEnd - $tagStart + 1);
        $parsedTag = parseTag($tag, $allowedTags);

        // тег не из белого списка, неверный атрибут или неверная форма тега.
        if ($parsedTag === false) {
            return false;
        }

        if ($parsedTag['closing']) {
            // Проверяем, что закрывающий тег должен соответствовать последнему открытому тегу.
            if (empty($stack) || array_pop($stack) !== $parsedTag['name']) {
                return false;
            }
        } else {
            // Проверяем, что вложенные ссылки являются некорректной HTML-комбинацией.
            if ($parsedTag['name'] === 'a' && in_array('a', $stack, true)) {
                return false;
            }

            // Окрывающий тег кладется в стек для последующей проверки вложенности.
            $stack[] = $parsedTag['name'];
        }

        $position = $tagEnd + 1;
    }

    return empty($stack);
}

/**
 * Находит позицию закрывающей угловой скобки текущего тега.
 *
 * Символ `>` внутри кавычек атрибута не завершает тег, а повторный `<`
 * внутри тега считается ошибкой XHTML-разметки.
 */
function findTagEnd(string $post, int $start): int|false
{
    $quote = null;
    $length = strlen($post);

    for ($i = $start + 1; $i < $length; $i++) {
        $char = $post[$i];

        if ($quote !== null) {
            if ($char === $quote) {
                $quote = null;
            } elseif ($char === '<') {
                return false;
            }

            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;
            continue;
        }

        if ($char === '<') {
            return false;
        }

        if ($char === '>') {
            return $i;
        }
    }

    return false;
}

/**
 * Разбирает один HTML/XHTML-тег.
 *
 * Возвращает массив с именем тега и признаком закрывающего тега либо false,
 * если тег запрещен, написан неверно или содержит запрещенные атрибуты.
 */
function parseTag(string $tag, array $allowedTags): array|false
{
    $inner = substr($tag, 1, -1);

    // XHTML-тег не может быть пустым или начинаться с пробела после <.
    if ($inner === '' || preg_match('/^\s/', $inner)) {
        return false;
    }

    if ($inner[0] === '/') {
        // Закрывающий тег может содержать только /, имя разрешенного тега и пробелы перед >.
        if (!preg_match('/^\/([a-z]+)\s*$/', $inner, $matches)) {
            return false;
        }

        $name = $matches[1];

        return array_key_exists($name, $allowedTags)
            ? ['name' => $name, 'closing' => true]
            : false;
    }

    // В задании разрешены только парные теги, поэтому самозакрывающиеся формы запрещены.
    if (preg_match('/\/\s*$/', $inner)) {
        return false;
    }

    // Выделяем имя открывающего тега и хвост с атрибутами.
    if (!preg_match('/^([a-z]+)(.*)$/s', $inner, $matches)) {
        return false;
    }

    $name = $matches[1];
    $attributesSource = $matches[2];

    if (!array_key_exists($name, $allowedTags)) {
        return false;
    }

    // Если после имени тега что-то есть, оно должно отделяться пробелом.
    if ($attributesSource !== '' && !preg_match('/^\s/', $attributesSource)) {
        return false;
    }

    $attributes = parseAttributes($attributesSource);

    if ($attributes === false) {
        return false;
    }

    // У каждого тега свой список разрешенных атрибутов.
    // Для code, i, strike и strong список пустой, значит любые атрибуты запрещены.
    foreach (array_keys($attributes) as $attributeName) {
        if (!in_array($attributeName, $allowedTags[$name], true)) {
            return false;
        }
    }

    return ['name' => $name, 'closing' => false];
}

/**
 * Разбирает строку атрибутов открывающего тега.
 *
 * Поддерживает XHTML-форму `name="value"` и `name='value'`.
 * Возвращает ассоциативный массив атрибутов либо false при ошибке.
 */
function parseAttributes(string $source): array|false
{
    $attributes = [];
    $offset = 0;
    $length = strlen($source);

    while ($offset < $length) {
        // Остаток может состоять только из пробелов после последнего атрибута.
        if (preg_match('/\G\s+\z/', $source, $matches, 0, $offset)) {
            break;
        }

        // Проверяем, что регулярное выражение проверяет форму атрибута.
        // Нельзя писать атрибут без значения, без кавычек или с < внутри значения.
        $matched = preg_match(
            '/\G\s+([a-z]+)\s*=\s*(["\'])([^<]*)\2/s',
            $source,
            $matches,
            0,
            $offset
        );

        if (!$matched) {
            return false;
        }

        $name = $matches[1];
        $value = $matches[3];

        // дубли атрибутов считаются некорректной комбинацией.
        // Проверяем, что значение атрибута проверяется как XML-текст.
        if (array_key_exists($name, $attributes) || !validateXmlText($value, false)) {
            return false;
        }

        $attributes[$name] = $value;
        $offset += strlen($matches[0]);
    }

    return $attributes;
}

/**
 * Проверяет обычный текст или значение атрибута на XML/XHTML-валидность.
 *
 * Запрещает управляющие XML-символы, неэкранированный `&`,
 * неизвестные сущности и некорректные числовые ссылки на символы.
 */
function validateXmlText(string $text, bool $rejectCdataClose = true): bool
{
    if ($text === '') {
        return true;
    }

    // XML 1.0 запрещает большинство управляющих символов.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) {
        return false;
    }

    // Последовательность `]]>` нельзя встретить в обычном XML-тексте.
    if ($rejectCdataClose && strpos($text, ']]>') !== false) {
        return false;
    }

    // Разрешены только пять стандартных XML-сущностей и числовые ссылки.
    if (preg_match('/&(?!amp;|lt;|gt;|quot;|apos;|#[0-9]+;|#x[0-9a-fA-F]+;)/', $text)) {
        return false;
    }

    // Если числовых ссылок нет, дополнительных проверок не требуется.
    if (!preg_match_all('/&#(x[0-9a-fA-F]+|[0-9]+);/', $text, $matches)) {
        return true;
    }

    // Числовая ссылка должна указывать на разрешенный XML code point.
    foreach ($matches[1] as $reference) {
        if ($reference[0] === 'x') {
            $codePoint = hexdec(substr($reference, 1));
        } else {
            $codePoint = (int)$reference;
        }

        if (!isValidXmlCodePoint($codePoint)) {
            return false;
        }
    }

    return true;
}

/**
 * Проверяет, разрешен ли Unicode code point правилами XML 1.0.
 */
function isValidXmlCodePoint(int $codePoint): bool
{
    return $codePoint === 0x9
        || $codePoint === 0xA
        || $codePoint === 0xD
        || ($codePoint >= 0x20 && $codePoint <= 0xD7FF)
        || ($codePoint >= 0xE000 && $codePoint <= 0xFFFD)
        || ($codePoint >= 0x10000 && $codePoint <= 0x10FFFF);
}
