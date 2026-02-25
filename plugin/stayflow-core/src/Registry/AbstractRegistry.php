<?php

declare(strict_types=1);

namespace StayFlow\Registry;

/**
 * RU: Базовый класс реестров для хранения данных через Options API.
 * EN: Base registry class for storing data via Options API.
 */
abstract class AbstractRegistry
{
    /**
     * RU: Возвращает ключ option конкретного реестра.
     * EN: Returns option key for the concrete registry.
     */
    abstract protected function optionKey(): string;

    /**
     * RU:
     * Сохраняет весь набор данных реестра.
     * Никаких автозапусков, вызывается вручную.
     *
     * EN:
     * Persists full registry dataset.
     * Manual call only.
     *
     * @param array<string,mixed> $data
     */
    public function save(array $data): void
    {
        $key = $this->optionKey();

        if (get_option($key, null) === null) {
            add_option($key, $data, '', false);
            return;
        }

        update_option($key, $data, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $data = get_option($this->optionKey(), []);
        return is_array($data) ? $data : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->all();
        return $data[$key] ?? $default;
    }
}