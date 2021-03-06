======================
What's new in Pomm 1.2
======================

Pomm 1.2 proposes a lot of enhancements over previous versions. First, Pomm's database layer has been migrated from PDO to the native PHP Postgresql library. This offers a tremendous boost from a performance point of view but also it opens access to features specific to Postgresql (notifications) while fixing some of the issues in Pomm's tracker (binary converter). 

This release is not just about migrating from PDO, it also offers a new PSR-3 compliant logger interface, automatic and transparent prepared statements pool support, raw type, generic composite type converter and type, coupled configuration / converter system etc.

Native PHP's pgsql library
==========================

Faster collections
------------------

Collections are iterators on results so all results are not automatically fetched in memory but retrieved on demand. Sometimes you want to iterate twice on a result set and if PDO pretends it works using scrollable cursor, it doesn't at least with Postgres. Using the native lib gives collection to transparently proposes scrollable cursors without having to keep previous results in memory.

The collection system has been factorised in one ``Collection`` class. The good news is that you can iterate twice over the iterator using different filters, it will return you different results.

Database events at your fingertips
----------------------------------

Postgresql proposes a handy asynchronous messaging system driven by the commands ``LISTEN`` and ``NOTIFY``. This is really useful to trigger parallel computations from a PHP code::

    // from your main code
    $post = $connection
        ->getMapFor('MyProject\Blog\Post')
        ->createAndSaveObject($post_values);

    $connection->notify('index_posts', $post['post_id']);

The previous code saves a new ``Post`` in the database (from cleaned values hopefully) and sends an asynchronous message named ``index_posts`` passing the primary key as payload with no impact on performances. From here, another PHP running script can trigger indexing when it receives such notification::

    // IndexPostObseverTask.php
    $post_map = $connection->getMapFor('MyProject\Blog\Post');
    $observer = $connection->createObserver()
        ->listen('index_posts');

    while(true)
    {
        while(!$data = $observer->getNotification())
        {
            sleep(1); // nothing to do, wait.
        }

        $post = $post_map->findByPK(array('post_id' => $data['payload']));

        if ($post)
        {
            // index post in an external full text engine
        }
        else
        {
            // log the issue
        }
    }

The observer script above simply listen to ``index_posts`` asynchronous events and launch the index action in parallel.

Queries values placeholders
---------------------------

If you are used to the previous versions of Pomm, one of the only change you will see is the way values are escaped from your SQL queries. Using PDO, prepared queries looks like this::

    SELECT field1, field2 FROM my_table WHERE field2 IN (?, ?, ?) AND field1 BETWEEN ? AND ? ;

Use of generic placeholder is nice but using the question mark as placeholder is not a good choice with Postgresql since it is used in many operators and can cause collisions.

The native PHP's pgsql lib, uses the `$n` form as placeholder. It ovoid collisions with operators but this is a positional parameter form::

    SELECT field1, field2 FROM my_table WHERE field2 IN ($1, $2, $3) AND field1 BETWEEN $4 AND $5 ;

When looking at the query above, it is easy to understand positional parameters are a pain when it is about building dynamic queries. Pomm proposes the following form to solve the puzzle::

    SELECT field1, field2 FROM my_table WHERE field2 IN ($*, $*, $*) AND field1 BETWEEN $* AND $* ;

If you are upgrading from previous version of Pomm, you will have to migrate your existing queries so they use `$*` instead of `?`. Of course, this is also needed for your queries using the ``Where`` class::

    $where = \Pomm\Query\Where::createWhereIn('field2', array($val1, $val2, $val3))
        ->andWhere('field1 BETWEEN $* AND $*', array($val4, $val5));

Binary type full support
------------------------

The ``bytea`` converter using native escaping functions is now able to read and write binary strings and arrays of such.

Other features
==============

Database configuration
----------------------

Since, the previous versions of Pomm were sensitive to server's configuration, a new configuration system took place. As it is possible to configure the way Postgresql presents data like timestamps or intervals, it has a huge impact on converters. The previous converters were assuming the database was running using the default parameters. Now, Pomm configures the connection's parameters like date format to get better performances from the converters. It is possible to set the database configuration using the ``configuration`` key in your connection string. The default parameters are the following:

 * bytea_output = escape
 * intervalstyle = ISO_8601
 * datestyle = ISO

You can add other runtime client configuration parameters (see http://www.postgresql.org/docs/9.0/static/runtime-config-client.html) or enforce the default ones but if you do so, you have to provide your own converters since this affects the way the database formats the data it returns to the client.

.. note:: The ``bytea_output`` setting is only supported with Postgresql 9.x. See Postgresql support below.


On the fly prepared queries
---------------------------

Of course, previous releases of Pomm were using prepared queries to escape values but it was not possible to use prepared statements directly from model's methods. This is now possible using the ``PreparedQuery`` class::

    $prepared_stmt = $connection->createPreparedQuery($sql_query);
    $prepared_stmt->execute($values);

This is useful when processing large amount of data that need to be built prior to insertion as this means sending thousand times the same query. Pomm 1.2 also inspects every query you issue and **checks if has not been prepared already**. If true, the prepared statement is reused. Otherwise a prepared statement is created and stored in the connection. This results in better performances without the programmer to worry about escaping and, more generally, about prepared statements.

::

    $results = $map->findWhere('age > ?', array($age1)); New prepared statement
    $results = $map->findWhere('age > ?', array($age2)); Automatic re-use of the previous prepared statement

Raw type
--------

Sometimes, you want to rely on Postgresql functions to set your objects' values without having to define a default value in the database. It is now possible to pass raw instructions to the database::

    $entity = $entity_map->createAndSaveObject(array('key1' => 'value1', 'key2' => new \Pomm\Type\RawString('my_pg_function(...)')));

This will issue the following query::

    INSERT INTO entity (key1, key2) VALUES ('value1', my_pg_function(...));

Row type
--------

Composite types were supported from Pomm 1.0 but it was necessary to create a type and a converter for them which could be a hassle. PgRow converts composite types into PHP arrays or objects. It is easy to extend this converter to create your own PHP equivalent classes to Postgresql composite structures or just use it as is if this fits your needs.

Multiple entities insertion
---------------------------

Alongside the ``createAndSaveObject()``, a new ``createAndSaveObjects()`` method can now save several entities using the same ``INSERT`` statement.

=========================
Migrating from 1.1 to 1.2
=========================

Blocking points
===============

Postgresql 8.4 end of life
--------------------------

Support of the version 8.4 of Postgresql has been dropped. Pomm 1.2 only works with Postgresql 9.x.

PHP 5.2 end of life
-------------------

Pomm 1.2 works with PHP 5.3.x or higher.

Filter Chain
------------

The filter chain used to hook code before or after queries has been removed as an unnecessary complicated expense. In the end, it was only needed to hook the logger (see below for the logger).

SQL queries
-----------

The new query system uses `$*` instead of `?` as values placeholder in prepared queries. This must be changed either in your raw SQL queries or in your ``Where`` conditions.

Soft points
===========

Collections
-----------

Collection system has been simplified, there is no more ``SimpleCollection``, the only class is a ``Collection`` providing scrollable cursor and filters. Filters method has been simplified, the clumsy ``unregisterFilter()`` method has been dropped in favor of a more general ``clearFilters()`` one.

Logger
------

With the filter chain re worked, the logger part has been replaced by a more generic support of any PSR-3 compliant logger (ie Monolog).

Remote fields methods
---------------------

``getRemoteSelectFields()`` and ``createFromForeign()`` methods have been removed from the ``BaseObjectMap`` class.

Range types
-----------

Range types ``TsRange`` and ``NumberRange`` constructor's signature has changed.
