<?php

use Phore\AiHarness\Content\AiFrontMatter;
use Phore\Schema\Parser\SchemaParser;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ArticleHeader
{
    /** Human-readable article title. */
    public string $title;

    /** Workflow status such as draft or published. */
    public string $status;
}

$headerSchema = (new SchemaParser())->parseClass(ArticleHeader::class);

$document = new AiFrontMatter(
    <<<MD
---
title: Initial title
status: draft
---
# Initial title

Short introduction.
MD,
    fileName: 'article.md',
    headerSchema: $headerSchema,
);

// Header-only changes keep the Markdown body untouched and validate the result.
$published = $document->headerEdit('Set status to published.');

// Body-only changes keep the structured header untouched.
$expanded = $document->bodyEdit('Expand the introduction to two paragraphs.');

// edit() may intentionally change both areas in one operation.
$renamed = $document->edit('Rename the article to "Release Notes" and update the H1.');

var_dump($published->header, $expanded->body->rawData, $renamed->rawData);
