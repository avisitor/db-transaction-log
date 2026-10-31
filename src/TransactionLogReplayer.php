<?php

declare(strict_types=1);

namespace DbTransactionLog;

/**
 * Replays a transaction log entry by re-executing the logged operation.
 *
 * This class provides the core replay logic. Apps should extend this class
 * to provide app-specific functionality via:
 * - Overriding $allowedOperations
 * - Overriding resolveClass() to add app-specific class resolution
 */
class TransactionLogReplayer
{
    /**
     * Only recorded write operations may be replayed — not arbitrary public
     * DBBase methods. Override in subclasses to add app-specific operations.
     *
     * @var string[]
     */
    public static array $allowedOperations = [];

    /**
     * Replay a logged transaction.
     *
     * @param string $classToken The class name or short token
     * @param string $operation The method name to invoke
     * @param array $params The parameters to pass to the method
     * @return TransactionLogReplayResult
     */
    public function replay(string $classToken, string $operation, array $params): TransactionLogReplayResult
    {
        // Resolve the class using app-provided resolution or default
        $resolvedClass = $this->resolveClass($classToken);

        if ($resolvedClass === null) {
            return new TransactionLogReplayResult(
                false,
                $classToken,
                $operation,
                '',
                '',
                'class not found or not a database class'
            );
        }

        // Method guard: must match pattern and be public
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $operation)) {
            return new TransactionLogReplayResult(
                false,
                $classToken,
                $operation,
                '',
                '',
                'invalid method name'
            );
        }

        if (!method_exists($resolvedClass, $operation)) {
            return new TransactionLogReplayResult(
                false,
                $classToken,
                $operation,
                '',
                '',
                'method does not exist'
            );
        }

        if (!in_array($operation, self::$allowedOperations, true)) {
            return new TransactionLogReplayResult(
                false,
                $classToken,
                $operation,
                '',
                '',
                'operation not allowed for replay'
            );
        }

        $method = new \ReflectionMethod($resolvedClass, $operation);
        if (!$method->isPublic()) {
            return new TransactionLogReplayResult(
                false,
                $classToken,
                $operation,
                '',
                '',
                'method is not public'
            );
        }

        // Arg mapping
        $declaredParams = $method->getParameters();
        $args = [];

        if (count($declaredParams) > 0) {
            $firstParam = $declaredParams[0];
            $firstParamName = $firstParam->getName();
            $hasKeyForFirst = array_key_exists($firstParamName, $params);
            $hasDefault = $firstParam->isDefaultValueAvailable();

            // Check if any declared param name appears as a key
            $anyDeclaredParamNameAppears = false;
            foreach ($declaredParams as $dp) {
                if (array_key_exists($dp->getName(), $params)) {
                    $anyDeclaredParamNameAppears = true;
                    break;
                }
            }

            // If first param has no key, no default, and no declared param name appears -> pass whole array
            if (!$hasKeyForFirst && !$hasDefault && !$anyDeclaredParamNameAppears) {
                $args = [$params];
            } else {
                // Walk declared params in order
                foreach ($declaredParams as $i => $dp) {
                    $name = $dp->getName();

                    if (array_key_exists($name, $params)) {
                        // Key present -> use value
                        $args[] = $params[$name];
                    } elseif ($dp->isDefaultValueAvailable()) {
                        // No key but has default -> check if a later param has a key
                        $hasLaterKey = false;
                        for ($j = $i + 1; $j < count($declaredParams); $j++) {
                            if (array_key_exists($declaredParams[$j]->getName(), $params)) {
                                $hasLaterKey = true;
                                break;
                            }
                        }

                        if ($hasLaterKey) {
                            // Use the default to keep positions
                            $args[] = $dp->getDefaultValue();
                        } else {
                            // Stop here (don't add more args)
                            break;
                        }
                    } else {
                        // No key, no default -> refuse
                        return new TransactionLogReplayResult(
                            false,
                            $classToken,
                            $operation,
                            '',
                            '',
                            'cannot map logged parameters to method signature'
                        );
                    }
                }
            }
        }

        // Build invocation string (truncated to 200 characters, UTF-8 safe)
        $invocation = "{$classToken}->{$operation}(" . var_export($args, true) . ")";
        if (mb_strlen($invocation, 'UTF-8') > 200) {
            $invocation = mb_substr($invocation, 0, 200, 'UTF-8');
        }

        // Execution
        try {
            $obj = new $resolvedClass();
        } catch (\Throwable $e) {
            return new TransactionLogReplayResult(
                false,
                $classToken,
                $operation,
                $invocation,
                '',
                get_class($e) . ': ' . $e->getMessage()
            );
        }

        $levelBefore = ob_get_level();
        try {
            ob_start();
            $ret = $obj->$operation(...$args);
            // Drain all buffers down to the level before we started
            // (method may have opened its own buffers without closing them)
            $output = '';
            while (ob_get_level() > $levelBefore) {
                $output .= ob_get_clean();
            }

            $resultText = $output . var_export($ret, true);

            return new TransactionLogReplayResult(
                true,
                $classToken,
                $operation,
                $invocation,
                $resultText,
                ''
            );
        } catch (\Throwable $e) {
            // Drain all buffers down to the level before we started
            while (ob_get_level() > $levelBefore) {
                ob_end_clean();
            }
            return new TransactionLogReplayResult(
                false,
                $classToken,
                $operation,
                $invocation,
                '',
                get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Resolve a class token to a fully-qualified class name.
     *
     * Apps should override this method to provide app-specific class resolution.
     * The default implementation checks for the class token as-is and with
     * Common\DB\ prefix.
     *
     * @param string $classToken The class token to resolve
     * @return string|null Fully qualified class name or null if not found
     */
    protected function resolveClass(string $classToken): ?string
    {
        $candidates = [$classToken, 'Common\\DB\\' . $classToken];
        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, \Common\DB\DBBase::class)) {
                return $candidate;
            }
        }
        return null;
    }
}
