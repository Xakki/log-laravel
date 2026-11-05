<?php

namespace Xakki\Log\Laravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class SqlLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($logStack = config('logging.dailySqlStack')) {
            $sqlSlowLogAll = (int) config('logging.sqlSlowLogAll');
            $sqlSlowLogSelect = (int) config('logging.sqlSlowLogForSelect');
            DB::listen(function (QueryExecuted $query) use ($logStack, $sqlSlowLogAll, $sqlSlowLogSelect) {
                // QueryExecuted::$time is already in milliseconds (Laravel docs it as such).
                $milliseconds = (int) round($query->time);

                if (stripos($query->sql, 'telescope') !== false) {
                    return;
                }
                // Grammar output is lowercase and wraps identifiers in quotes/backticks
                // (e.g. "select * from `users` where ..."), so the keyword match must be
                // case-insensitive and the table match must tolerate a leading quote char.
                preg_match('/(UPDATE|DELETE\s+FROM|INSERT\s+INTO|FROM)\s+[`"\[]?([\w.]+)[`"\]]?/i', $query->sql, $m);
                // preg_replace returns null on error; (string) keeps strtoupper() typed.
                $sqlType = isset($m[1]) ? strtoupper((string) preg_replace('/\s+/', ' ', trim($m[1]))) : '';
                $table = $m[2] ?? '';
                if ($sqlType == 'FROM') {
                    $sqlType = 'SELECT';
                } elseif ($sqlType == 'DELETE FROM') {
                    $sqlType = 'DELETE';
                } elseif ($sqlType == 'INSERT INTO') {
                    $sqlType = 'INSERT';
                }

                // SELECTs get their own (typically lower) threshold when configured;
                // everything else falls back to the general slow-query threshold.
                $threshold = ($sqlType === 'SELECT' && $sqlSlowLogSelect) ? $sqlSlowLogSelect : $sqlSlowLogAll;
                if ($threshold && $threshold > $milliseconds) {
                    return;
                }

                $bind = '';
                if (env('APP_DEBUG') && count($query->bindings) < 20) {
                    $bind = [];
                    foreach ($query->bindings as $k => $v) {
                        if (is_string($v) && mb_strlen($v) > 512) {
                            $v = '`string(' . mb_strlen($v) . ')`';
                        }
                        $bind[$k] = $v;
                    }
                    $bind = json_encode($bind);
                }

                Log::channel($logStack)->info($query->sql, [
                    'db_table' => $table,
                    'db_time_ms' => $milliseconds,
                    'db_bindings' => $bind,
                    'tag' => 'sql',
                    'sql_type' => $sqlType,
                ]);
            });
        }
    }
}
