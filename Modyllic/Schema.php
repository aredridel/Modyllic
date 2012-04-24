<?php
/**
 * Copyright © 2011 Online Buddies, Inc. - All Rights Reserved
 *
 * @package Modyllic
 * @author bturner@online-buddies.com
 */

require_once "Modyllic/SQL.php";
require_once "Modyllic/Types.php";

// Components
require_once "Modyllic/Schema/View.php";
require_once "Modyllic/Schema/Table.php";

/**
 * A base class for various schema objects.  Handles generic things like
 * providing previous values for the diff engine.  In a perfect world this
 * would be a runtime trait applied by the diff engine.
 */
class Modyllic_Diffable {
    public $from;
}

/**
 * A collection of SQL entities comprising a complete schema
 */
class Modyllic_Schema extends Modyllic_Diffable {
    public $tables = array();
    public $routines = array();
    public $views = array();
    public $events = array();
    public $triggers = array();
    const DEFAULT_NAME = "database";
    public $name = self::DEFAULT_NAME;
    public $name_is_default = true;
    const DEFAULT_CHARSET = "utf8";
    public $charset = self::DEFAULT_CHARSET;
    const DEFAULT_COLLATE = "utf8_general_ci";
    public $collate = self::DEFAULT_COLLATE;
    public $docs = "";

    function reset() {
        $this->triggers       = array();
        $this->routines       = array();
        $this->tables         = array();
        $this->views          = array();
        $this->events         = array();
        $this->name           = self::DEFAULT_NAME;
        $this->name_is_default  = true;
        $this->charset        = self::DEFAULT_CHARSET;
        $this->collate        = self::DEFAULT_COLLATE;
        $this->docs           = "";
    }

    function name_is_default() {
        return ($this->name == self::DEFAULT_NAME);
    }

    function set_name( $name ) {
        $this->name_is_default = ( $name == self::DEFAULT_NAME );
        $this->name = $name;
    }

    function merge( $schema ) {
        if ( $this->name_is_default ) {
            $this->set_name($schema->name);
        }
        if ( $this->charset == self::DEFAULT_CHARSET ) {
            $this->charset = $schema->charset;
        }
        if ( $this->collate == self::DEFAULT_COLLATE ) {
            $this->collate = $schema->collate;
        }
        if ( $this->docs == "" ) {
            $this->docs = $schema->docs;
        }
        foreach ($schema->tables as $table) {
            $this->add_table($table);
        }
        foreach ($schema->routines as $routine) {
            $this->add_routine($routine);
        }
        foreach ($schema->views as $view) {
            $this->add_view($view);
        }
        foreach ($schema->events as $event) {
            $this->add_event($event);
        }
        foreach ($schema->triggers as $trigger) {
            $this->add_trigger($trigger);
        }
    }

    /**
     * @param Modyllic_Schema_Table $table
     */
    function add_table( $table ) {
        $this->tables[$table->name] = $table;
        return $table;
    }

    /**
     * @param Modyllic_Routine $routine
     */
    function add_routine( $routine ) {
        $this->routines[$routine->name] = $routine;
        return $routine;
    }

    /**
     * @param Modyllic_Event $event
     */
    function add_event( $event ) {
        $this->events[$event->name] = $event;
        return $event;
    }

    /**
     * @param Modyllic_Trigger $trigger
     */
    function add_trigger( $trigger ) {
        $this->triggers[$trigger->name] = $trigger;
        return $trigger;
    }

    /**
     * @param Modyllic_Schema_View $view
     */
    function add_view( $view ) {
        $this->views[$view->name] = $view;
        return $view;
    }

    function unquote_sql_str($sql) {
        $tok = new Modyllic_Tokenizer( $sql );
        return $tok->next()->unquote();
    }

    /**
     * Generates a meta table entry that wasn't in the schema
     */
    function load_sqlmeta() {
        # If we already have an SQLMETA table then this is a load directly
        # from a database (or a dump from a database).  We'll want to
        # convert that back into our usual metadata.
        if ( isset($this->tables['SQLMETA']) and isset($this->tables['SQLMETA']->data) ) {
            foreach ($this->tables['SQLMETA']->data as &$row) {
                $kind = $this->unquote_sql_str($row['kind']);
                $which = $this->unquote_sql_str($row['which']);
                $meta = json_decode($this->unquote_sql_str($row['value']), true);
                $obj = null;
                switch ($kind) {
                    case 'TABLE':
                        if ( isset($this->tables[$which]) ) {
                            $obj = $this->tables[$which];
                        }
                        break;
                    case 'COLUMN':
                        list($table,$col) = explode(".",$which);
                        if ( isset($this->tables[$table]) and isset($this->tables[$table]->columns[$col]) ) {
                            $obj = $this->tables[$table]->columns[$col];
                        }
                        break;
                    case 'INDEX':
                        list($table,$index) = explode(".",$which);
                        if ( isset($this->tables[$table]) and isset($this->tables[$table]->indexes[$index]) ) {
                            $obj = $this->tables[$table]->indexes[$index];
                        }
                        break;
                    case 'ROUTINE':
                        if ( isset($this->routines[$which]) ) {
                            $obj = $this->routines[$which];
                        }
                        break;
                    default:
                        throw new Exception("Unknown kind of metadata $kind found in SQLMETA");
                        break;
                }
                if ( isset($obj) ) {
                    foreach ($meta as $metakey=>&$metavalue) {
                        $obj->$metakey = $metavalue;
                    }
                }
            }
            unset($this->tables['SQLMETA']);
        }
    }

    /**
     * @param Modyllic_Schema $other
     */
    function schema_def_equal_to( $other ) {
        if ( $this->charset != $other->charset ) { return false; }
        if ( $this->collate != $other->collate ) { return false; }
        return true;
    }

    function equal_to( $other ) {
        if ( ! $this->schema_def_equal_to($other) ) { return false; }
        if ( count($this->tables) != count($other->tables) ) { return false; }
        if ( count($this->routines) != count($other->routines) ) { return false; }
        if ( count($this->events) != count($other->events) ) { return false; }
        if ( count($this->triggers) != count($other->triggers) ) { return false; }
        if ( count($this->views) != count($other->views) ) { return false; }
        foreach ($this->tables as $key=>&$table) {
            if ( ! $table->equal_to( $other->tables[$key] ) ) { return false; }
        }
        foreach ($this->routines as $key=>&$routine) {
            if ( ! $routine->equal_to( $other->routines[$key] ) ) { return false; }
        }
        foreach ($this->events as $key=>&$event) {
            if ( ! $event->equal_to( $other->events[$key] ) ) { return false; }
        }
        foreach ($this->views as $key=>&$view) {
            if ( ! $view->equal_to( $other->views[$key] ) ) { return false; }
        }
        return true;
    }
}


class Modyllic_CodeBody extends Modyllic_Diffable {
    public $body = "BEGIN\nEND";
    /**
     * @returns string Strips any comments from the body of the routine--
     * this allows the body to be compared to the one in the database,
     * which never has comments.
     */
    function _body_no_comments() {
        $stripped = $this->body;
        # Strip C style comments
        $stripped = preg_replace('{/[*].*?[*]/}s', '', $stripped);
        # Strip shell and SQL style comments
        $stripped = preg_replace('/(#|--).*/', '', $stripped);
        # Strip leading and trailing whitespace
        $stripped = preg_replace('/^[ \t]+|[ \t]+$/m', '', $stripped);
        # Collapse repeated newlines
        $stripped = preg_replace('/\n+/', "\n", $stripped);
        return $stripped;
    }

    function equal_to($other) {
        if ( get_class($other) != get_class($this) )   { return false; }
        if ( $this->_body_no_comments() != $other->_body_no_comments() ) { return false; }
        return true;
    }

}

/**
 * A collection of attributes describing an event
 */
class Modyllic_Event extends Modyllic_CodeBody {
    public $name;
    public $schedule;
    public $preserve = false;
    public $status;
    public $docs = "";

    /**
     * @param string $name
     */
    function __construct($name) {
        $this->name = $name;
    }

    function equal_to($other) {
        if ( ! parent::equal_to($other) ) { return false; }
        if ( $this->schedule != $other->schedule ) { return false; }
        if ( $this->preserve != $other->preserve ) { return false; }
        if ( $this->status != $other->status ) { return false; }
        return true;
    }
}

/**
 * A collection of attributes describing an event
 */
class Modyllic_Trigger extends Modyllic_CodeBody {
    public $name;
    public $time;
    public $event;
    public $table;
    public $body;
    public $docs = "";

    /**
     * @param string $name
     */
    function __construct($name) {
        $this->name = $name;
    }

    function equal_to($other) {
        if ( ! parent::equal_to($other)     ) { return false; }
        if ( $this->time != $other->time   ) { return false; }
        if ( $this->event != $other->event ) { return false; }
        if ( $this->body != $other->body   ) { return false; }
        return true;
    }
}

/**
 * A collection of attributes describing a stored routine
 */
class Modyllic_Routine extends Modyllic_CodeBody {
    public $name;
    public $args = array();
    const ARGS_TYPE_DEFAULT = "LIST";
    public $args_type = self::ARGS_TYPE_DEFAULT;
    const DETERMINISTIC_DEFAULT = false;
    public $deterministic = self::DETERMINISTIC_DEFAULT;
    const ACCESS_DEFAULT = "CONTAINS SQL";
    public $access = self::ACCESS_DEFAULT;
    public $returns;
    const TXNS_NONE = 0;
    const TXNS_CALL = 1;
    const TXNS_HAS  = 2;
    const TXNS_DEFAULT = self::TXNS_NONE;
    public $txns = self::TXNS_DEFAULT;
    public $docs = '';

    /**
     * @param string $name
     */
    function __construct($name) {
        $this->name = $name;
    }

    /**
     * @param Modyllic_Routine $other
     * @returns bool True if $other is equivalent to $this
     */
    function equal_to($other) {
        if ( ! parent::equal_to($other) ) { return false; }
        if ( $this->deterministic != $other->deterministic ) { return false; }
        if ( $this->access        != $other->access )        { return false; }
        if ( $this->args_type     != $other->args_type )     { return false; }
        if ( $this->txns          != $other->txns )          { return false; }
        $thisargc = count($this->args);
        $otherargc = count($other->args);
        if ( $thisargc != $otherargc ) { return false; }
        for ( $ii=0; $ii<$thisargc; ++$ii ) {
            if ( ! $this->args[$ii]->equal_to( $other->args[$ii] ) ) { return false; }
        }
        return true;
    }
}

/**
 * A stored procedure, which is exactly like the base routine class
 */
class Modyllic_Proc extends Modyllic_Routine {
    const RETURNS_TYPE_DEFAULT = "NONE";
    public $returns = array("type"=>self::RETURNS_TYPE_DEFAULT);
    function equal_to($other) {
        if ( ! parent::equal_to( $other ) ) { return false; }
        if ( $this->returns != $other->returns ) { return false; }
        return true;
    }
}

/**
 * A collection of attributes describing a stored function
 */
class Modyllic_Func extends Modyllic_Routine {
    function equal_to($other) {
        if ( ! parent::equal_to( $other ) ) { return false; }
        if ( ! $this->returns->equal_to( $other->returns ) ) { return false; }
        return true;
    }
}

/**
 * A collection of attributes describing an argument to a stored procedure
 * or function.
 */
class Modyllic_Arg extends Modyllic_Diffable {
    public $name;
    public $type;
    public $dir = "IN";
    public $docs = "";
    function to_sql() {
        $sql = "";
        if ( $dir != "IN" ) {
            $sql .= "$dir ";
        }
        $sql .= Modyllic_SQL::quote_ident($name)." ";
        $sql .= $type->to_sql();
        return $sql;
    }
    function equal_to($other) {
        if ( $this->name != $other->name ) { return false; }
        if ( $this->dir != $other->dir ) { return false; }
        if ( ! $this->type->equal_to($other->type) ) { return false; }
        return true;
    }
}


