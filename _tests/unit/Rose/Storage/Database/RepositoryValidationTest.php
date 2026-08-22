<?php

declare(strict_types = 1);

/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 */

namespace Register\Rose\Test\Storage\Database;

use Codeception\Test\Unit;
use Register\Rose\Exception\InvalidArgumentException;
use Register\Rose\Storage\Database\MysqlRepository;

final class RepositoryValidationTest extends Unit
{
    public function testRejectsInvalidPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MysqlRepository(new \PDO('sqlite::memory:'), 'bad;DROP', []);
    }

    public function testRejectsInvalidTableOverride(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MysqlRepository(new \PDO('sqlite::memory:'), 'ok_prefix', ['toc' => 'toc;DROP']);
    }
}
