<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use HTMLPurifier_HTML5Config;
use Illuminate\Support\Facades\File;

class HtmlSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $cachePath = storage_path('app/purifier');
        File::ensureDirectoryExists($cachePath);

        $config = class_exists(HTMLPurifier_HTML5Config::class)
            ? HTMLPurifier_HTML5Config::createDefault()
            : HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('HTML.DefinitionID', 'milosevac-editor-html5');
        $config->set('HTML.DefinitionRev', 1);
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', implode(',', [
            'p[class]',
            'br',
            'strong',
            'b',
            'em',
            'i',
            'u',
            's',
            'blockquote[cite]',
            'ul',
            'ol',
            'li',
            'h2',
            'h3',
            'h4',
            'a[href|title|target|rel]',
            'figure[class]',
            'figcaption',
            'img[src|alt|title|width|height]',
            'table',
            'thead',
            'tbody',
            'tr',
            'th[colspan|rowspan]',
            'td[colspan|rowspan]',
            'code',
            'pre',
        ]));
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('URI.SafeIframeRegexp', '%^https://(www\.)?(youtube\.com/embed/|player\.vimeo\.com/video/)%');
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.AutoParagraph', true);
        $config->set('HTML.Nofollow', true);
        $config->set('HTML.TargetBlank', true);
        if ($definition = $config->maybeGetRawHTMLDefinition()) {
            $definition->addElement('figure', 'Block', 'Flow', 'Common');
            $definition->addElement('figcaption', 'Block', 'Flow', 'Common');
        }

        $this->purifier = new HTMLPurifier($config);
    }

    public function clean(?string $html): string
    {
        return trim($this->purifier->purify((string) $html));
    }
}
