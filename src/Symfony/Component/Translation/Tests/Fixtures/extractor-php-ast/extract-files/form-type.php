<?php

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

class FooType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('foo1', null, [
            'label' => 'label.foo1',
            'placeholder' => 'placeholder.foo1',
            'help' => 'help.foo1',
        ]);
    }
}
