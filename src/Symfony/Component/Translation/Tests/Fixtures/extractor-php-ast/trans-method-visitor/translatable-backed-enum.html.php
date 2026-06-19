This template is used for translation message extraction tests
<?php
// @See https://symfony.com/doc/current/reference/forms/types/enum.html#example-usage

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum TextAlign: string implements TranslatableInterface
{
    case Left = 'Left aligned';
    case Center = 'Center aligned';
    case Right = 'Right aligned';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::Left  => $translator->trans('text_align.left.label', locale: $locale),
            self::Center => $translator->trans('text_align.center.label', locale: $locale),
            self::Right  => $translator->trans('text_align.right.label', locale: $locale),
        };
    }
}
