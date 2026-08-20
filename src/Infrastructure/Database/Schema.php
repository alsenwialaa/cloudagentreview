<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

final class Schema
{
    public const VERSION = '2.5.4';
    public const OPTION = 'ysai_schema_version';

    public static function conversations(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ysai_v2_conversations';
    }

    public static function messages(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ysai_v2_messages';
    }

    public static function turns(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ysai_v2_turns';
    }

    public static function rateLimits(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ysai_v2_rate_limits';
    }

    /**
     * Structural invariants that must exist before the schema version can be
     * published. Types, nullability, defaults, auto-increment behavior,
     * uniqueness, and index column order are part of the persistence model.
     *
     * @return array<string,array{
     *   columns:array<string,array{type:string,nullable:bool,default:string|null,auto_increment:bool,textual:bool}>,
     *   indexes:array<string,array{unique:bool,columns:list<string>}>
     * }>
     */
    public static function requirements(): array
    {
        $char36 = '/^char\(36\)$/D';
        $char64 = '/^char\(64\)$/D';
        $bigintUnsigned = '/^bigint(?:\(20\))? unsigned$/D';
        $intUnsigned = '/^int(?:\(10\))? unsigned$/D';
        $datetime = '/^datetime$/D';
        $longtext = '/^longtext$/D';

        return array(
            self::conversations() => array(
                'columns' => array(
                    'id' => self::column($char36, false, null, false, true),
                    'token_hash' => self::column($char64, false, null, false, true),
                    'lifecycle_state' => self::column('/^varchar\(16\)$/D', false, 'active', false, true),
                    'memory_json' => self::column($longtext, true, null, false, true),
                    'created_at' => self::column($datetime, false),
                    'session_started_at' => self::column($datetime, false),
                    'last_activity_at' => self::column($datetime, false),
                    'expires_at' => self::column($datetime, false),
                ),
                'indexes' => array(
                    'PRIMARY' => self::index(true, array('id')),
                    'expires_at' => self::index(false, array('expires_at')),
                    'last_activity_at' => self::index(false, array('last_activity_at')),
                ),
            ),
            self::turns() => array(
                'columns' => array(
                    'id' => self::column($bigintUnsigned, false, null, true),
                    'conversation_id' => self::column($char36, false, null, false, true),
                    'client_turn_id' => self::column('/^varchar\(64\)$/D', false, null, false, true),
                    'request_hash' => self::column($char64, false, null, false, true),
                    'status' => self::column('/^varchar\(16\)$/D', false, null, false, true),
                    'claim_version' => self::column($bigintUnsigned, false, '1'),
                    'lease_seconds' => self::column($intUnsigned, false, '1200'),
                    'response_json' => self::column($longtext, true, null, false, true),
                    'error_code' => self::column('/^varchar\(64\)$/D', true, null, false, true),
                    'created_at' => self::column($datetime, false),
                    'updated_at' => self::column($datetime, false),
                ),
                'indexes' => array(
                    'PRIMARY' => self::index(true, array('id')),
                    'conversation_turn' => self::index(true, array('conversation_id', 'client_turn_id')),
                    'updated_at' => self::index(false, array('updated_at')),
                ),
            ),
            self::messages() => array(
                'columns' => array(
                    'id' => self::column($bigintUnsigned, false, null, true),
                    'conversation_id' => self::column($char36, false, null, false, true),
                    'turn_id' => self::column($bigintUnsigned, false),
                    'role' => self::column('/^varchar\(16\)$/D', false, null, false, true),
                    'content' => self::column($longtext, false, null, false, true),
                    'payload_json' => self::column($longtext, true, null, false, true),
                    'created_at' => self::column($datetime, false),
                ),
                'indexes' => array(
                    'PRIMARY' => self::index(true, array('id')),
                    'conversation_id' => self::index(false, array('conversation_id', 'id')),
                    'turn_role' => self::index(true, array('turn_id', 'role')),
                    'turn_id' => self::index(false, array('turn_id')),
                ),
            ),
            self::rateLimits() => array(
                'columns' => array(
                    'bucket_hash' => self::column($char64, false, null, false, true),
                    'scope' => self::column('/^varchar\(40\)$/D', false, null, false, true),
                    'window_ends_at' => self::column($datetime, false),
                    'hits' => self::column($intUnsigned, false, '1'),
                ),
                'indexes' => array(
                    'PRIMARY' => self::index(true, array('bucket_hash')),
                    'window_ends_at' => self::index(false, array('window_ends_at')),
                ),
            ),
        );
    }

    /** @return array{type:string,nullable:bool,default:string|null,auto_increment:bool,textual:bool} */
    private static function column(
        string $type,
        bool $nullable,
        ?string $default = null,
        bool $autoIncrement = false,
        bool $textual = false
    ): array {
        return array(
            'type' => $type,
            'nullable' => $nullable,
            'default' => $default,
            'auto_increment' => $autoIncrement,
            'textual' => $textual,
        );
    }

    /** @param list<string> $columns @return array{unique:bool,columns:list<string>} */
    private static function index(bool $unique, array $columns): array
    {
        return array('unique' => $unique, 'columns' => $columns);
    }

    /** @return list<string> */
    public static function statements(): array
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $conversations = self::conversations();
        $messages = self::messages();
        $turns = self::turns();
        $rateLimits = self::rateLimits();

        return array(
            "CREATE TABLE {$conversations} (
                id char(36) NOT NULL,
                token_hash char(64) NOT NULL,
                lifecycle_state varchar(16) NOT NULL DEFAULT 'active',
                memory_json longtext NULL,
                created_at datetime NOT NULL,
                session_started_at datetime NOT NULL,
                last_activity_at datetime NOT NULL,
                expires_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY expires_at (expires_at),
                KEY last_activity_at (last_activity_at)
            ) ENGINE=InnoDB {$charset};",
            "CREATE TABLE {$turns} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                conversation_id char(36) NOT NULL,
                client_turn_id varchar(64) NOT NULL,
                request_hash char(64) NOT NULL,
                status varchar(16) NOT NULL,
                claim_version bigint(20) unsigned NOT NULL DEFAULT 1,
                lease_seconds int(10) unsigned NOT NULL DEFAULT 1200,
                response_json longtext NULL,
                error_code varchar(64) NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY conversation_turn (conversation_id,client_turn_id),
                KEY updated_at (updated_at)
            ) ENGINE=InnoDB {$charset};",
            "CREATE TABLE {$messages} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                conversation_id char(36) NOT NULL,
                turn_id bigint(20) unsigned NOT NULL,
                role varchar(16) NOT NULL,
                content longtext NOT NULL,
                payload_json longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY conversation_id (conversation_id,id),
                UNIQUE KEY turn_role (turn_id,role),
                KEY turn_id (turn_id)
            ) ENGINE=InnoDB {$charset};",
            "CREATE TABLE {$rateLimits} (
                bucket_hash char(64) NOT NULL,
                scope varchar(40) NOT NULL,
                window_ends_at datetime NOT NULL,
                hits int(10) unsigned NOT NULL DEFAULT 1,
                PRIMARY KEY  (bucket_hash),
                KEY window_ends_at (window_ends_at)
            ) ENGINE=InnoDB {$charset};",
        );
    }
}
