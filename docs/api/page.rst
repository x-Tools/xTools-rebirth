.. _page:

########
Page API
########

API endpoints related to a single page.

Article info
============
``GET /api/page/articleinfo/{project}/{article}/{format}``

Get basic information about the history of a page.

**Parameters:**

* ``project`` (**required**) - Project domain or database name.
* ``article`` (**required**) - Full page title.

**Example:**

Get basic information about `Albert Einstein <https://en.wikipedia.org/wiki/Albert_Einstein>`_.

    https://xtools.wmflabs.org/api/page/articleinfo/en.wikipedia.org/Albert_Einstein

Prose
=====
``GET /api/page/prose/{project}/{article}``

Get statistics about the prose (characters, word count, etc.) and referencing of a page.

**Parameters:**

* ``project`` (**required**) - Project domain or database name.
* ``article`` (**required**) - Full page title.

**Example:**

Get prose statistics of `Albert Einstein <https://en.wikipedia.org/wiki/Albert_Einstein>`_.

    https://xtools.wmflabs.org/api/page/prose/en.wikipedia.org/Albert_Einstein

Links
=====
``GET /api/page/links/{project}/{article}``

Get the number of in and outgoing links and redirects to the given page.

**Parameters:**

* ``project`` (**required**) - Project domain or database name.
* ``article`` (**required**) - Full page title.

**Example:**

Get links statistics of `Albert Einstein <https://en.wikipedia.org/wiki/Albert_Einstein>`_.

    https://xtools.wmflabs.org/api/page/links/en.wikipedia.org/Albert_Einstein

Assessments
===========
``GET /api/page/assessments/{project}/{articles}``

Get assessment data of the given articles, including the overall quality classifications,
along with a list of the WikiProjects and their classifications and importance levels.
You can optionally pass in ``?classonly=1`` to get only the overall quality assessment.

**Parameters:**

* ``project`` (**required**) - Project domain or database name.
* ``article`` (**required**) - One or more full page titles, separated by pipes ``|``

**Example:***

Get the assessment data on the English Wikipedia articles
`Albert Einstein <https://en.wikipedia.org/wiki/Albert_Einstein>`_. and
`Bob Dylan <https://en.wikipedia.org/wiki/Bob_Dylan>`_..

    `<https://xtools.wmflabs.org/api/page/assessments/enwiki/Albert_Einstein|Bob_Dylan>`_
    `<https://xtools.wmflabs.org/api/page/assessments/en.wikipedia/Albert_Einstein|Bob_Dylan>`_
    `<https://xtools.wmflabs.org/api/page/assessments/en.wikipedia.org/Albert_Einstein|Bob_Dylan>`_

Same as above, but get only the overall quality assessments.

    `<https://xtools.wmflabs.org/api/page/assessments/en.wikipedia.org/Albert_Einstein|Bob_Dylan?classonly=1>`_
