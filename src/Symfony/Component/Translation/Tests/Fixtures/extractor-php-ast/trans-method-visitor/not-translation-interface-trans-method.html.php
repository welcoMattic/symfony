This template is used for translation message extraction tests
<?php
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class TransFoormer
{
    /**
     * Method used to have the same name as TranslatorInterface::trans
     */
    public function trans(string $foo): void
    {
    }
}

$transfoormer = new TransFoormer();
$transfoormer->trans('skip');
