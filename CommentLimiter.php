<?php

declare(strict_types=1);

class CommentLimiter
{
    private const COMMENTS_LIMIT = 3;
    private const WINDOW_SECONDS = 10;
    private const WINDOW_MICROSECONDS = self::WINDOW_SECONDS * 1000000;

    /**
     * Redis-клиент из расширения phpredis.
     */
    private Redis $redis;

    /**
     * Префикс ключей, чтобы данные лимитера не пересекались с другими данными Redis.
     */
    private string $keyPrefix;

    /**
     * Локальный счетчик нужен, чтобы элементы sorted set были уникальными
     * даже при нескольких вызовах в одну и ту же микросекунду.
     */
    private int $sequence = 0;

    /**
     * Принимает готовое подключение к Redis и необязательный префикс ключей.
     * Redis передается снаружи, чтобы класс не отвечал за создание соединения
     * и мог использоваться с любыми настройками подключения приложения.
     *
     * @param Redis $redis
     * @param string $keyPrefix
     */
    public function __construct(Redis $redis, string $keyPrefix = 'comment_limiter')
    {
        $this->redis = $redis;
        $this->keyPrefix = $keyPrefix;
    }

    /**
     * Проверяет, может ли пользователь отправить очередной комментарий.
     *
     * Состояние хранится в Redis sorted set:
     * - score: время разрешенного комментария в микросекундах;
     * - value: уникальный идентификатор события комментария.
     *
     * Проверка и запись выполняются атомарно через Lua-скрипт, поэтому лимит
     * корректно работает даже при параллельных запросах.
     *
     * Возвращает true, если комментарий разрешен и уже записан в лимит.
     * Возвращает false, если пользователь уже отправил 3 комментария за 10 секунд.
     *
     * @param int $userId
     * @return bool
     */
    public function canPost(int $userId): bool
    {
        $now = (int)floor(microtime(true) * 1000000);
        $windowStart = $now - self::WINDOW_MICROSECONDS;
        $key = $this->buildKey($userId);
        $member = $this->buildMember($now);

        $result = $this->redis->eval(
            $this->getLimitScript(),
            [
                $key,
                (string)$windowStart,
                (string)$now,
                $member,
                (string)self::COMMENTS_LIMIT,
                (string)self::WINDOW_SECONDS,
            ],
            1
        );

        return (int)$result === 1;
    }

    /**
     * Формирует Redis-ключ для конкретного пользователя.
     *
     * Например, при префиксе comment_limiter и userId 10 получится ключ
     * comment_limiter:10.
     *
     * @param int $userId
     * @return string
     */
    private function buildKey(int $userId): string
    {
        return $this->keyPrefix . ':' . $userId;
    }

    /**
     * Формирует уникальное значение элемента sorted set.
     *
     * В score Redis хранится время комментария, а value должен быть уникальным,
     * поэтому в него добавлены время, id процесса и локальный счетчик.
     *
     * @param int $now
     * @return string
     */
    private function buildMember(int $now): string
    {
        return $now . ':' . getmypid() . ':' . (++$this->sequence);
    }

    /**
     * Возвращает Lua-скрипт для атомарной проверки лимита в Redis.
     *
     * Скрипт делает три действия как одну неделимую операцию:
     * удаляет старые комментарии, проверяет количество актуальных комментариев
     * и при доступном лимите добавляет новый комментарий.
     */
    private function getLimitScript(): string
    {
        return <<<'LUA'
local key = KEYS[1]
local windowStart = tonumber(ARGV[1])
local now = tonumber(ARGV[2])
local member = ARGV[3]
local limit = tonumber(ARGV[4])
local ttl = tonumber(ARGV[5])

redis.call('ZREMRANGEBYSCORE', key, '-inf', windowStart)

if redis.call('ZCARD', key) >= limit then
    redis.call('EXPIRE', key, ttl)
    return 0
end

redis.call('ZADD', key, now, member)
redis.call('EXPIRE', key, ttl)

return 1
LUA;
    }
}
