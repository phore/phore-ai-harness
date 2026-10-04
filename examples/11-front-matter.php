<?php

use Phore\AiHarness\Content\AiFrontMatter;
require dirname(__DIR__) . '/vendor/autoload.php';

final class ArticleHeader
{
    /** Human-readable article title. */
    public string $title;

    /** Workflow status such as draft or published. */
    public string $status;
}

$document = AiFrontMatter::fromFile(
    '/path/to/article.md',
    headerSchema: ArticleHeader::class,
    id: 'article',
    aliases: ['Seite', 'Release-Artikel'],
);

// The class name is parsed by phore/schema, including the field descriptions.
// The ID and aliases are also available as references in prompts.

$document->ai_text('Summarize Seite in one sentence.');

// Header-only changes keep the Markdown body untouched and validate the result.
$published = $document->headerEdit('Set status to published.');

// Body-only changes keep the structured header untouched.
$expanded = $document->bodyEdit('Expand the introduction to two paragraphs.');

// edit() may intentionally change both areas in one operation.
$renamed = $document->edit('Rename the article to "Release Notes" and update the H1.');

var_dump($published->header, $expanded->body->rawData, $renamed->rawData);
