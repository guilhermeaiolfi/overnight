<?php
namespace ON\Config;

use Laminas\ConfigAggregatorParameters\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException as SymfonyParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

use function array_walk_recursive;
use function is_array;
use function is_numeric;

class OwnParameterPostProcessor
{
    private array $parameters;

    public function __invoke(array $config): array
    {
        try {
            $parameters = $this->getResolvedParameters($config);
            //$parameters = $this->getResolvedParameters([ "app" => $config["app"] ]);

            array_walk_recursive($config, static function (mixed &$value) use ($parameters): void {
                $value = $parameters->unescapeValue($parameters->resolveValue($value));
            });
        } catch (SymfonyParameterNotFoundException $exception) {
            throw ParameterNotFoundException::fromException($exception);
        }

        $allParameters        = $parameters->all();
        $config['parameters'] = $allParameters;

        return $config;
    }

    
    private function resolveNestedParameters(array $values, string $prefix = ''): array
    {
        $convertedValues = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($values as $key => $value) {
            // Do not provide numeric keys as single parameter
            if (is_numeric($key)) {
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $convertedValues[$prefix . $key] = $value;
            if (is_array($value)) {
                $convertedValues += $this->resolveNestedParameters($value, $prefix . $key . '.');
            }
        }

        return $convertedValues;
    }

    private function getResolvedParameters($config): ParameterBag
    {
        $resolved = $this->resolveNestedParameters($config);
        $bag      = new ParameterBag($resolved);

        $bag->resolve();
        return $bag;
    }
}
