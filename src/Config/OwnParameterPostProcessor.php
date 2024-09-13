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

    public function __invoke(array $current, array $chunk = null): array
    {
        $second_pass = isset($chunk);
        if (!$second_pass) {
            $chunk = $current;
        }
        try {

            // parameters to be resolved, in this case, all config values could be used
            $parameters = $this->getResolvedParameters($current, $second_pass);
            //$parameters = $this->getResolvedParameters([ "app" => $config["app"] ]);

            //here is where we go to every and each index in the array and resolve the value
            // using the parameters defined earlier
            array_walk_recursive($chunk, static function (mixed &$value) use ($parameters): void {
                $value = $parameters->unescapeValue($parameters->resolveValue($value));
            });
        } catch (SymfonyParameterNotFoundException $exception) {
            throw ParameterNotFoundException::fromException($exception);
        }

        $allParameters        = $parameters->all();
        $config['parameters'] = $allParameters;

        return $chunk;
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

    private function getResolvedParameters($config, $second_pass = false): ParameterBag
    {
        $resolved = $this->resolveNestedParameters($config);
        $bag      = new ParameterBag($resolved);

        if (!$second_pass) {
            $bag->resolve();
        }

        return $bag;
    }
}
