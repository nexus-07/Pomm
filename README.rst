=================================================
POMM: The PHP Object Model Manager for Postgresql
=================================================

.. image:: https://secure.travis-ci.org/chanmix51/Pomm.png?branch=master
   :target: http://travis-ci.org/#!/chanmix51/Pomm

.. image:: https://scrutinizer-ci.com/g/chanmix51/Pomm/badges/quality-score.png?s=5766ac7091629c3af205bbcca8623bd2e8cfe85e
   :target: https://scrutinizer-ci.com/g/chanmix51/Pomm/

.. image:: https://poser.pugx.org/Pomm/Pomm/version.png
   :target: https://poser.pugx.org/

.. image:: https://poser.pugx.org/Pomm/Pomm/d/total.png
   :target: https://packagist.org/packages/pomm/pomm

Note
****

This branch is still in Release Candidate state which means it might contain bugs or the documentation might be incomplete or inaccurate. **Testers welcome** Feel free to open bugs or talk with us on #pomm channel on freenode. Although one might use the version 1.1 in production, if you are starting a new project right now, this new version is probably the best one to go with.

What is Pomm ?
**************
**Pomm** is a *lightweight*, *fast*, *efficient* and *powerful* PHP object manager for the Postgresql relational database. **Pomm is not an ORM**, it is more like an object oriented framework for Postgresql in PHP implementing the `identity map <http://en.wikipedia.org/wiki/Identity_map>`_ design pattern and proposing convenient functionalities. Dropping the abstraction layer makes programmers able to take advantage of most of Postgresql's awesome features.

Pomm works with PHP 5.3 and Postgresql 9.0 and above.

You can reach

* `documentation <http://pomm.coolkeums.org/documentation/manual-1.2>`_
* `code examples <http://pomm.coolkeums.org/documentation/examples>`_
* `mailing list <https://groups.google.com/forum/#!forum/pommproject>`_

What does Pomm propose ?
************************

* Database introspection and model generation
* Lazy hydration and on the fly type conversion
* SQL queries, virtual fields, finders and pagers
* Collection, filters and query filters
* A Where clause builder
* Security and debugging tools

Database introspection and model generation
*******************************************
Once you have designed your database using your favorite tool, Pomm can generate PHP model classes for you by inspecting `tables <http://www.postgresql.org/docs/8.4/static/sql-createtable.html>`_ and `views <http://www.postgresql.org/docs/8.4/static/sql-createview.html>`_. It checks the `schema <http://www.postgresql.org/docs/8.4/static/ddl-schemas.html>`_ where your database objects are stored so generated classes use according `PHP namespaces <http://www.php.net/manual/en/language.namespaces.php>`_, there will not be naming collision anymore ! Of Course, Postgresql's `table inheritance <http://www.postgresql.org/docs/8.4/static/ddl-inherit.html>`_ is supported.

Lazy hydration and on the fly type conversion
*********************************************
`Queries <http://pomm.coolkeums.org/documentation/manual#custom-queries>`_ return `collections <http://pomm.coolkeums.org/documentation/manual#collections>`_ which are scrollable iterators on results. Fetched objects are hydrated on demand for minimal memory consumption and data are `converted <http://pomm.coolkeums.org/documentation/manual#database-and-converters>`_ from/to Postgresql. Boolean in Pg are boolean in PHP, `arrays <http://www.postgresql.org/docs/8.4/static/arrays.html>`_ in Pg are arrays in PHP, `geometric types <http://www.postgresql.org/docs/8.4/static/datatype-geometric.html>`_ are converted into geometric PHP objects. Of course this is extensible and custom database types can be converted into custom PHP classes. Almost all standard and geometric types are supported plus `range <http://www.postgresql.org/docs/9.2/static/rangetypes.html>`_, `HStore <http://www.postgresql.org/docs/8.4/static/hstore.html>`_ and `ltree <http://www.postgresql.org/docs/8.4/static/ltree.html>`_ extensions.

SQL queries, virtual fields, finders and pagers
***********************************************
Of course 80% of the queries of a web applications are like ``SELECT * FROM my_table WHERE ...``  So there is a method for that. You can configure what you do want in the select fields in case you would like to add your classes extra properties. You can even use them with the converter system with extra fields adding them as virtual fields. For the more complicated queries, SQL is the way to go. You can use all the awesome `operators <http://www.postgresql.org/docs/8.4/static/functions.html>`_, `full text search <http://www.postgresql.org/docs/8.4/static/textsearch.html>`_, `window functions <http://www.postgresql.org/docs/8.4/static/tutorial-window.html>`_, `CTEs and recursive queries <http://www.postgresql.org/docs/8.4/static/queries-with.html>`_, (add your preferred feature here).

A Where clause builder
**********************
Sometimes, you want to create a ``WHERE`` clause dynamically. Pomm proposes a Where class to let you ``AND`` and/or ``OR`` your conditions passing the values as argument each time for escaping. There is even a ``WhereIn`` method with an array of values as parameter.

Security and debugging tools
****************************
All queries are prepared and pooled so they are re used if you send twice the same query with different parameters. The values passed as argument are automatically escaped by the server. Furthermore, the converter system ensures there is no type enforcing.

Pomm 1.2 can uses any PSR-3 compatible logger (ie `Monolog <https://github.com/Seldaek/monolog>`).


=====================
How to install Pomm ?
=====================

The easy way: composer
**********************
Using `composer <http://packagist.org/>`_ installer and autoloader is probably the easiest way to install Pomm and get it running. What you need is just a ``composer.json`` file in the root directory of your project:


::

  {
  "require": {
      "pomm/pomm": "master-dev"
    }
  }

Invoking ``composer.phar`` will automagically download Pomm, install it in a ``vendor`` directory and set up the according autoloader.

Using Pomm with a PHP framework
*******************************

* Silex `PommServiceProvider <https://github.com/chanmix51/PommServiceProvider>`_
* Symfony2 `PommBundle <https://github.com/chanmix51/PommBundle>`_

With Silex, it is possible to bootstrap a kitchen sink using this `gist <https://gist.github.com/chanmix51/3402026>`, in an empty directory just issue the command::

    wget -O - 'https://gist.github.com/chanmix51/3402026/raw/3cf2125316687be6d3ab076e66f291b68b422ce7/create-pomm-silex.sh' | bash

And follow the instructions.

===========================
How to contribute to Pomm ?
===========================

That's very easy with github:

* Send feedback to `@PommProject <https://twitter.com/#!/PommProject>`_ on twitter or by mail at <hubert DOT greg AT gmail DOT com>
* Report bugs (very appreciated)
* Fork and PR (very very appreciated)
* Send vacuum tubes to the author (actual preferred are russians 6Φ12Π, 6Ж43Π, 6Ж38Π, 6C19Π)

Running tests
*************

.. code-block:: sh

    psql -c 'CREATE DATABASE pomm_test' -U postgres -h 127.0.0.1
    psql -c 'CREATE EXTENSION hstore' -U postgres -h 127.0.0.1 pomm_test
    psql -c 'CREATE EXTENSION ltree' -U postgres -h 127.0.0.1 pomm_test

    phpunit --configuration tests/phpunit.travis.xml
