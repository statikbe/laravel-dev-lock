<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Laravel Boost discovers a package's skills at `<package>/resources/boost/skills/<dir>/SKILL.md`
 * and reads the YAML frontmatter for a name and a description. A skill missing either is skipped
 * without a warning, so the contract is asserted here instead of noticed in a host app.
 */
function boostSkillPath(): string
{
    return dirname(__DIR__, 2).'/resources/boost/skills/statik-dev-lock-development/SKILL.md';
}

it('ships the boost skill where laravel boost looks for it', function () {
    expect(boostSkillPath())->toBeReadableFile();
});

it('gives the boost skill the frontmatter boost requires to register it', function () {
    $content = (string) file_get_contents(boostSkillPath());

    expect($content)->toMatch('/^\s*---\s*\n(.*?)\n---\s*\n/s');

    preg_match('/^\s*---\s*\n(.*?)\n---\s*\n/s', $content, $matches);

    $frontmatter = Yaml::parse($matches[1]);

    expect($frontmatter['name'])->toBe('statik-dev-lock-development')
        ->and($frontmatter['description'])->toBeString()->not->toBeEmpty();
});
