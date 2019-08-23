Sidus/AdminDemo
===================

Simple application using Symfony 4.3 and Bootstrap 4 to demonstrate the potential of the
[Sidus/AdminBundle](https://github.com/VincentChalnot/SidusAdminBundle).

See live demo: [http://admin-demo.sidus.fr](http://admin-demo.sidus.fr)

Getting started
---------------

Install the project (Docker container, dependencies with Composer, etc.) with `make install`

Then get a shell access with `make shell`

Inside the container you need to:

- Create the schema `php bin/console doctrine:schema:create` 
- Install fixtures `php bin/console app:fixtures:init` 

🛎 [http://admin-demo.sidus.localhost/](http://admin-demo.sidus.localhost/)

Demo account login and password is `admin` (it can be found in `config/packages/security.yaml`)

You are now all set! 🙌
