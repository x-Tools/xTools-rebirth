.. _user:

########
User API
########

API endpoints related to a user.

Number of pages created
=======================
``GET /api/user/pages_count/{project}/{username}/{namespace}/{redirects}``

Get the number of pages created by the user in the given namespace.

**Parameters:**

* ``project`` (**required**) - Project domain or database name.
* ``username`` (**required**) - Account's username.
* ``namespace`` - Namespace ID or ``all`` for all namespaces.
* ``redirects`` - One of 'noredirects' (default), 'onlyredirects' or 'all' for both.

**Example:**

Get the number of mainspace, non-redirect pages created by `Jimbo Wales <https://en.wikipedia.org/wiki/User:Jimbo_Wales>`_ on the English Wikipedia.

    https://xtools.wmflabs.org/api/user/pages_count/en.wikipedia/Jimbo_Wales

Get the number of article talk pages created by `Jimbo Wales <https://en.wikipedia.org/wiki/User:Jimbo_Wales>`_ that are redirects.

    https://xtools.wmflabs.org/api/user/pages_count/en.wikipedia/Jimbo_Wales/1/onlyredirects

Automated edit counter
======================
``GET /api/user/automated_editcount/{project}/{username}/{namespace}/{start}/{end}/{offset}/{tools}``

Get the number of (semi-)automated edits made by the given user in the given namespace and date range.
You can optionally pass in ``?tools=1`` to get individual counts of each (semi-)automated tool that was used.

**Parameters:**

* ``project`` (**required**) - Project domain or database name.
* ``username`` (**required**) - Account's username.
* ``namespace`` - Namespace ID or ``all`` for all namespaces.
* ``start`` - Start date in the format ``YYYY-MM-DD``. Leave this and ``end`` blank to retrieve the most recent data.
* ``end`` - End date in the format ``YYYY-MM-DD``. Leave this and ``start`` blank to retrieve the most recent data.
* ``tools`` - Set to any non-blank value to include the tools that were used and thier counts.

**Example:**

Get the number of (semi-)automated edits made by `Jimbo Wales <https://en.wikipedia.org/wiki/User:Jimbo_Wales>`_ on the English Wikipedia.

    https://xtools.wmflabs.org/api/user/automated_editcount/en.wikipedia/Jimbo_Wales

Get a list of the known (semi-)automated tools used by `Jimbo Wales <https://en.wikipedia.org/wiki/User:Jimbo_Wales>`_ in the mainspace on the English Wikipedia, and how many times they were used.

    https://xtools.wmflabs.org/api/user/automated_editcount/en.wikipedia/Jimbo_Wales/0///1

Non-automated edits
===================
``GET /api/user/nonautomated_edits/{project}/{username}/{namespace}/{start}/{end}/{offset}``

Get non-automated contributions for the given user, namespace and date range.

**Parameters:**

* ``project`` (**required**) - Project domain or database name.
* ``username`` (**required**) - Account's username.
* ``namespace`` (**required**) - Namespace ID or  ``all`` for all namespaces.
* ``start`` - Start date in the format ``YYYY-MM-DD``. Leave this and ``end`` blank to retrieve the most recent contributions.
* ``end`` - End date in the format ``YYYY-MM-DD``. Leave this and ``start`` blank to retrieve the most recent contributions.
* ``offset`` - Number of edits from the start date.

**Example:**

Get the newest non-automated mainspace contributions made by `Jimbo Wales <https://en.wikipedia.org/wiki/User:Jimbo_Wales>`_ on the English Wikipedia.

    https://xtools.wmflabs.org/api/user/nonautomated_edits/en.wikipedia/Jimbo_Wales/0

Edit summaries
==============
``GET /api/user/edit_summeries/{project}/{username}/{namespace}``

Get statistics about a user's usage of edit summaries.

**Parameters:**

* ``project`` (**required**) - Project domain or database name.
* ``username`` (**required**) - Account's username.
* ``namespace`` - Namespace ID or ``all`` for all namespaces.

**Example:**

Get `Jimbo Wales <https://en.wikipedia.org/wiki/User:Jimbo_Wales>`_'s edit summary statistics on the English Wikipedia.

    https://xtools.wmflabs.org/api/user/edit_summeries/en.wikipedia/Jimbo_Wales

Top edits
=========
``GET /api/user/top_edits/{project}/{username}/{namespace}/{article}``

Get the top-edited pages by a user, or get all edits made by a user to a specific page.

**Parameters:**

* ``project`` (**required**) - Project domain or database name.
* ``username`` (**required**) - Account's username.
* ``namespace`` - Namespace ID or ``all`` for all namespaces. Defaults to the mainspace. Leave this blank if you are also supplying a full page title as the ``article``.
* ``article`` - Full page title if ``namespace`` is omitted. If ``namespace`` is blank, do not include the namespace in the page title.

**Example:**

Get the top edits made by `Jimbo Wales <https://en.wikipedia.org/wiki/User:Jimbo_Wales>`_ in the mainspace.

    https://xtools.wmflabs.org/api/user/top_edits/en.wikipedia/Jimbo_Wales

Get the top edits made by `Jimbo Wales <https://en.wikipedia.org/wiki/User:Jimbo_Wales>`_ in the userspace.

    https://xtools.wmflabs.org/api/user/top_edits/en.wikipedia/Jimbo_Wales/2

Get the top edits made by `Jimbo Wales <https://en.wikipedia.org/wiki/User:Jimbo_Wales>`_ to the page `Talk:Naveen Jain <https://en.wikipedia.org/wiki/Talk:Naveen_Jain>`_.

    https://xtools.wmflabs.org/api/user/top_edits/en.wikipedia/Jimbo_Wales//Talk:Naveen_Jain
    https://xtools.wmflabs.org/api/user/top_edits/en.wikipedia.org/Jimbo_Wales/1/Naveen_Jain
