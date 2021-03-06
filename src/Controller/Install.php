<?php

namespace Foolz\FoolFrame\Controller;

use Foolz\FoolFrame\Model\Auth;
use Foolz\FoolFrame\Model\Config;
use Foolz\FoolFrame\Model\DoctrineConnection;
use Foolz\FoolFrame\Model\Notices;
use Foolz\FoolFrame\Model\Schema;
use Foolz\FoolFrame\Model\SchemaManager;
use Foolz\FoolFrame\Model\System;
use Foolz\FoolFrame\Model\Uri;
use Foolz\FoolFrame\Model\Users;
use Foolz\FoolFrame\Model\Validation\ActiveConstraint\Trim;
use Foolz\FoolFrame\Model\Validation\Constraint\EqualsField;
use Foolz\FoolFrame\Model\Validation\Validator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;

class Install extends Common
{
    /**
     * @var \Foolz\Theme\Theme
     */
    protected $theme;

    /**
     * @var \Foolz\Theme\Builder
     */
    protected $builder;

    /**
     * @var \Foolz\Theme\ParamManager
     */
    protected $param_manager;

    /**
     * @var Notices
     */
    protected $notices;

    /**
     * @var Uri
     */
    protected $uri;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var \Foolz\FoolFrame\Model\Install
     */
    protected $install;

    public function before()
    {
        $this->notices = $this->getContext()->getService('notices');
        $this->uri = $this->getContext()->getService('uri');
        $this->config = $this->getContext()->getService('config');
        $this->install = new \Foolz\FoolFrame\Model\Install($this->getContext());

        $theme_instance = \Foolz\Theme\Loader::forge('foolframe_admin');
        $theme_instance->addDir(VENDPATH.'foolz/foolframe/assets/themes-admin/');
        $theme_instance->setBaseUrl($this->uri->base().'foolframe/');
        $theme_instance->setPublicDir(DOCROOT.'foolframe/');
        $this->theme = $theme_instance->get('foolz/foolframe-theme-admin');

        $this->builder = $this->theme->createBuilder();
        $this->builder->createLayout('base');
        $this->builder->createPartial('navbar', 'install/navbar');
        $this->builder->getProps()->addTitle(_i('FoolFrame Installation'));

        $this->param_manager = $this->builder->getParamManager();
        $this->param_manager->setParams([
            'context' => $this->getContext(),
            'request' => $this->getRequest(),
            'notices' => $this->notices,
            'controller_title' => _i('FoolFrame Installation')
        ]);
    }

    public function process($action)
    {
        $procedure = [
            'welcome' => _i('Welcome'),
            'system_check' => _i('System Check'),
            'database_setup' => _i('Database Setup'),
            'create_admin' => _i('Admin Account'),
            'complete' => _i('Congratulations'),
        ];

        $this->builder->createPartial('sidebar', 'install/sidebar')
            ->getParamManager()->setParams(['sidebar' => $procedure, 'current' => $action]);
    }

    public function action_404()
    {
        $this->notices->set('warning', _i('Page not found.'));
        return new Response($this->builder->build(), 404);
    }

    public function action_index()
    {
        $this->process('welcome');
        $this->param_manager->setParam('method_title', _i('Welcome'));

        $this->builder->createPartial('body', 'install/welcome');
        return new Response($this->builder->build());
    }

    public function action_system_check()
    {
        $data = [];
        $data['system'] = System::getEnvironment($this->getContext());

        $this->process('system_check');
        $this->param_manager->setParam('method_title', _i('System Check'));

        $this->builder->createPartial('body', 'install/system_check')
            ->getParamManager()->setParams($data);
        return new Response($this->builder->build());
    }

    public function action_database_setup()
    {
        if ($this->getPost()) {
            $validator = new Validator();
            $validator
                ->add('hostname', _i('Database Hostname'), [new Trim(), new Assert\NotBlank()])
                ->add('prefix', _i('Table Prefix'), [new Trim()])
                ->add('username', _i('Username'), [new Trim(), new Assert\NotBlank()])
                ->add('database', _i('Database name'), [new Trim(), new Assert\NotBlank()]);

            $validator->validate($this->getPost());

            if (!$validator->getViolations()->count()) {
                $input = $validator->getFinalValues();
                $input['password'] = $this->getPost('password');
                $input['type'] = $this->getPost('type');

                if ($this->install->check_database($input)) {
                    $this->install->setup_database($input);

                    $dc = new DoctrineConnection($this->getContext(), $this->config);

                    $sm = SchemaManager::forge($dc->getConnection(), $dc->getPrefix());
                    Schema::load($this->getContext(), $sm);
                    $sm->commit();
                    $this->install->create_salts();

                    return new RedirectResponse($this->uri->create('install/create_admin'));
                } else {
                    $this->notices->set('warning', _i('Connection to specified database failed. Please check your connection details again.'));
                }
            } else {
                $this->notices->set('warning', $validator->getViolations()->getText());
            }
        }

        $this->process('database_setup');
        $this->param_manager->setParam('method_title', _i('Database Setup'));

        $this->builder->createPartial('body', 'install/database_setup');
        return new Response($this->builder->build());
    }

    public function action_create_admin()
    {
        // if an admin account exists, lock down this step and redirect to the next step instead
        /** @var Users $users */
        $users = $this->getContext()->getService('users');
        $check_users = $users->getAll();

        if ($check_users['count'] > 0) {
            return new RedirectResponse($this->uri->create('install/modules'));
        }

        if ($this->getPost()) {
            $validator = new Validator();
            $validator
                ->add('username', _i('Username'), [new Trim(), new Assert\NotBlank(), new Assert\Length(['min' => 4, 'max' => 32])])
                ->add('email', _i('Email'), [new Trim(), new Assert\NotBlank(), new Assert\Email()])
                ->add('password', _i('Password'), [new Trim(), new Assert\NotBlank(), new Assert\Length(['min' => 4, 'max' => 64])])
                ->add('confirm_password', _i('Confirm Password'), [new EqualsField(['field' => _i('Password'), 'value' => $this->getPost('password')])]);

            $validator->validate($this->getPost());

            if (!$validator->getViolations()->count()) {
                $input = $validator->getFinalValues();

                $auth = new Auth($this->getContext());

                list($id, $activation_key) = $auth->createUser($input['username'], $input['password'], $input['email']);
                $auth->activateUser($id, $activation_key);
                $auth->authenticateWithId($id);
                $user = $auth->getUser();
                $user->save(['group_id' => 100]);

                // leave the module installation later in case we must do something with users
                $this->install->install_modules();

                return new RedirectResponse($this->uri->create('install/complete'));
            } else {
                $this->notices->set('warning', $validator->getViolations()->getText());
            }
        }

        $this->process('create_admin');
        $this->param_manager->setParam('method_title', _i('Admin Account'));

        $this->builder->createPartial('body', 'install/create_admin');
        return new Response($this->builder->build());
    }

    public function action_complete()
    {
        $this->process('complete');
        $this->param_manager->setParam('method_title', _i('Congratulations'));

        $this->builder->createPartial('body', 'install/complete');
        return new Response($this->builder->build());
    }
}
