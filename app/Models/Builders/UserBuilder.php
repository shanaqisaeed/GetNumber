<?php

namespace App\Models\Builders;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class UserBuilder extends Builder
{
    /**
     * Add a basic where clause to the query, redirecting username/email to user_identities.
     *
     * @param  \Closure|string|array|\Illuminate\Contracts\Database\Query\Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_string($column)) {
            $col = strtolower(trim($column));

            if ($col === 'username' || $col === 'users.username') {
                $targetVal = func_num_args() === 2 ? $operator : $value;
                $targetOp = func_num_args() === 2 ? '=' : $operator;

                if ($targetOp === '=') {
                    return $this->whereHas('identities', function ($q) use ($targetVal) {
                        $q->where('provider', 'username')
                          ->where('provider_id', strtolower((string) $targetVal));
                    }, '>=', 1, $boolean);
                }
            }

            if ($col === 'email' || $col === 'users.email') {
                $targetVal = func_num_args() === 2 ? $operator : $value;
                $targetOp = func_num_args() === 2 ? '=' : $operator;

                if ($targetOp === '=') {
                    return $this->whereHas('identities', function ($q) use ($targetVal) {
                        $q->where(function ($sub) use ($targetVal) {
                            $sub->where('provider', 'email')
                                ->where('provider_id', strtolower((string) $targetVal))
                                ->orWhere('provider_email', strtolower((string) $targetVal));
                        });
                    }, '>=', 1, $boolean);
                }
            }
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * Add a "where in" clause to the query, redirecting username/email to user_identities.
     *
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if (is_string($column)) {
            $col = strtolower(trim($column));

            if ($col === 'username' || $col === 'users.username') {
                $list = array_map('strtolower', (array) $values);
                return $this->whereHas('identities', function ($q) use ($list) {
                    $q->where('provider', 'username')->whereIn('provider_id', $list);
                }, $not ? '<' : '>=', 1, $boolean);
            }

            if ($col === 'email' || $col === 'users.email') {
                $list = array_map('strtolower', (array) $values);
                return $this->whereHas('identities', function ($q) use ($list) {
                    $q->where(function ($sub) use ($list) {
                        $sub->where('provider', 'email')->whereIn('provider_id', $list)
                            ->orWhereIn('provider_email', $list);
                    });
                }, $not ? '<' : '>=', 1, $boolean);
            }
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }
}

