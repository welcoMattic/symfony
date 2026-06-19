This template is used for translation message extraction tests
<?php

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum BackedEnumWithValueConcatenationAtTheEnd: string implements TranslatableInterface
{
    case Foo = 'foo';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('backed_enum.value_concatenation.'.$this->value, locale: $locale);
    }
}

enum BackedEnumWithNameConcatenationAtTheEnd: string implements TranslatableInterface
{
    case Foo = 'foo';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('backed_enum.name_concatenation.'.$this->name, locale: $locale);
    }
}

enum BackedEnumWithValueConcatenationInTheMiddle: string implements TranslatableInterface
{
    case Foo = 'foo';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('backed_enum.value_concatenation.'.$this->value.'.label', locale: $locale);
    }
}

enum BackedEnumWithBothConcatenation: string implements TranslatableInterface
{
    case Foo = 'foo';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('backed_enum.both_concatenation.'.$this->name.'_'.$this->value, locale: $locale);
    }
}

enum BackedEnumWithIntergerScalarType: int implements TranslatableInterface
{
    case Foo = 0;

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('backed_enum.value_concatenation.'.$this->value, locale: $locale);
    }
}

enum BackedEnumWithSprintf: string implements TranslatableInterface
{
    case Foo = 'foo';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans(sprintf('backed_enum.value_sprintf.%s', $this->value), locale: $locale);
    }
}

enum BackedEnumWithTwoArgumentsInSprintf: string implements TranslatableInterface
{
    case Foo = 'foo';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans(sprintf('backed_enum.value_sprintf.%s.%s', $this->name, $this->value), locale: $locale);
    }
}

enum BackedEnumWithSprintfAndConcatenation: string implements TranslatableInterface
{
    case Foo = 'foo';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans(sprintf('backed_enum.value_sprintf.%s', $this->value).'.name_concatenation.'.$this->name, locale: $locale);
    }
}
