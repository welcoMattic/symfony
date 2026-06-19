<?php

use Symfony\Component\Validator\Constraints as Assert;

class Foo
{
    #[Assert\NotBlank(message: 'message')]
    public string $bar;
}
