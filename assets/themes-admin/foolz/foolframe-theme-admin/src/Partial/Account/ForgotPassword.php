<?php

namespace Foolz\FoolFrame\Theme\Admin\Partial\Account;

class ForgotPassword extends \Foolz\FoolFrame\View\View
{
    public function toString()
    {
        $form = $this->getForm();
        ?>
<?= $form->open(['class' => 'form-account', 'onsubmit' => 'fuel_set_csrf_token(this);']) ?>
    <?= $form->hidden('csrf_token', $this->getSecurity()->getCsrfToken()); ?>
    <h2 class="form-account-heading"><?= _i('Forgot Password') ?></h2>

    <?= $form->input([
        'class' => 'input-block-level',
        'name' => 'email',
        'type' => 'email',
        'value' => $this->getPost('email'),
        'placeholder' => _i('Email Address'),
        'required' => true
    ]) ?>

    <?= $form->submit(['class' => 'btn btn-primary', 'name' => 'submit', 'value' => _i('Submit')]) ?>

    <input type="button" onClick="window.location.href='<?= $this->getUri()->create('/admin/account/login/') ?>'" class="btn" value="<?= htmlspecialchars(_i('Back')) ?>" />
<?= $form->close() ?>
<?php
    }
}
