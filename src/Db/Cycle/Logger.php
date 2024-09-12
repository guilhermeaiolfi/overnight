<?php
namespace ON\Db\Cycle;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;


class Logger implements LoggerInterface//, \DebugBar\DataCollector\DataCollectorInterface
{
    use LoggerTrait;

    private $display;

    private $countWrites;
    private $countReads;
    private $messages;

    public function __construct()
    {
        $this->countWrites = 0;
        $this->countReads = 0;
    }
    public function getName() {
        return "CycleORM";
    }

    public function collect() {
        $messages = $this->messages;
        return array(
            'count' => count($messages),
            'messages' => $messages
        );
    }

    public function countWriteQueries(): int
    {
        return $this->countWrites;
    }

    public function countReadQueries(): int
    {
        return $this->countReads;
    }

    public function log($level, $message, array $context = []): void
    {
        if (!empty($context['elapsed'])) {
            $sql = strtolower($message);
            if (
                strpos($sql, 'insert') === 0 ||
                strpos($sql, 'update') === 0 ||
                strpos($sql, 'delete') === 0
            ) {
                $this->countWrites++;
            } else {
                if (!$this->isPostgresSystemQuery($sql)) {
                    $this->countReads++;
                }
            }
        }

        if (!$this->display) {
            return;
        }

        if ($level == LogLevel::ERROR) {
            $this->messages[] = $message;
            echo " <br />\n! \033[31m" . $message . "\033[0m";
        } elseif ($level == LogLevel::ALERT) {
            $this->messages[] = $message;
            echo " <br />\n! \033[35m" . $message . "\033[0m";
        } elseif (strpos($message, 'SHOW') === 0) {
            echo "<br /> \n> \033[34m" . $message . "\033[0m";
        } else {
            if ($this->isPostgresSystemQuery($message)) {
                $this->messages[] = $message;
                echo " <br />\n> \033[90m" . $message . "\033[0m";

                return;
            }

            if (strpos($message, 'SELECT') === 0) {
                $this->messages[] = $message;
                echo " <br />\n> \033[32m" . $message . "\033[0m";
            } elseif (strpos($message, 'INSERT') === 0) {
                $this->messages[] = $message;
                echo " <br />\n> \033[36m" . $message . "\033[0m";
            } else {
                $this->messages[] = $message;
                echo " <br />\n> \033[33m" . $message . "\033[0m";
            }
        }
    }

    public function display(): void
    {
        $this->display = true;
    }

    public function hide(): void
    {
        $this->display = false;
    }

    protected function isPostgresSystemQuery(string $query): bool
    {
        $query = strtolower($query);
        if (
            strpos($query, 'tc.constraint_name') ||
            strpos($query, 'pg_indexes') ||
            strpos($query, 'tc.constraint_name') ||
            strpos($query, 'pg_constraint') ||
            strpos($query, 'information_schema') ||
            strpos($query, 'pg_class')
        ) {
            return true;
        }

        return false;
    }
}