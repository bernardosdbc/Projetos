<?php

namespace App\Enums;

/**
 * Case order matters: it must match the MySQL `enum('priority', [...])` column
 * declaration in the migration. MySQL stores ENUM values as small integers in
 * declaration order, so `ORDER BY priority` sorts critical < high < normal < low
 * for free — no FIELD()/CASE hack needed for the mysql driver's claim query.
 * The Redis drivers keep a parallel PRIORITIES array that must stay in this
 * same order (they sweep ready lists/streams in priority order instead).
 */
enum JobPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
}
