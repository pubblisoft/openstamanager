<?php

namespace Controllers;

use App;
use Auth;
use Update;

class BaseController extends Controller
{
    public function index($request, $response, $args)
    {
        // Requisiti di OpenSTAManager
        if (!App::isConfigured()) {
            $response = $response->withRedirect($this->router->pathFor('requirements'));
        }

        // Inizializzazione
        if (!App::isConfigured()) {
            $response = $response->withRedirect($this->router->pathFor('configuration'));
        }

        // Installazione e/o aggiornamento
        elseif (Update::isUpdateAvailable()) {
            $response = $response->withRedirect($this->router->pathFor('update'));
        }

        // Configurazione informazioni di base
        elseif (Update::isUpdateAvailable() || !$this->database->isInstalled()) {
            $response = $response->withRedirect($this->router->pathFor('init'));
        }

        // Login
        elseif (!$this->auth->isAuthenticated()) {
            $response = $this->view->render($response, 'resources\views\login.php', $args);
        }

        // Redirect automatico al primo modulo disponibile
        else {
            $response = $this->redirectFirstModule($request, $response);
        }

        return $response;
    }

    public function loginAction($request, $response, $args)
    {
        $username = post('username');
        $password = post('password');

        if ($this->database->isConnected() && $this->database->isInstalled() && $this->auth->attempt($username, $password)) {
            $_SESSION['keep_alive'] = (filter('keep_alive') != null);

            // Rimozione log vecchi
            $this->database->query('DELETE FROM `zz_operations` WHERE DATE_ADD(`created_at`, INTERVAL 30*24*60*60 SECOND) <= NOW()');

            // Auto backup del database giornaliero
            if (setting('Backup automatico')) {
                $result = Backup::daily();

                if (!isset($result)) {
                    flash()->info(tr('Backup saltato perché già esistente!'));
                } elseif (!empty($result)) {
                    flash()->info(tr('Backup automatico eseguito correttamente!'));
                } else {
                    flash()->error(tr('Errore durante la generazione del backup automatico!'));
                }
            }

            $response = $this->redirectFirstModule($request, $response);
        } else {
            $status = $this->auth->getCurrentStatus();

            flash()->error(Auth::getStatus()[$status]['message']);

            $response = $response->withRedirect($this->router->pathFor('login'));
        }

        return $response;
    }

    public function logout($request, $response, $args)
    {
        Auth::logout();

        $response = $response->withRedirect($this->router->pathFor('login'));

        return $response;
    }

    public function info($request, $response, $args)
    {
        $response = $this->view->render($response, 'resources\views\info.php', $args);

        return $response;
    }

    public function bug($request, $response, $args)
    {
        $response = $this->view->render($response, 'resources\views\bug.php', $args);

        return $response;
    }

    protected function redirectFirstModule($request, $response)
    {
        $module = $this->auth->getFirstModule();
        if (!empty($module)) {
            $response = $response->withRedirect($this->router->pathFor('module', [
                'module_id' => $module,
            ]));
        } else {
            $response = $response->withRedirect($this->router->pathFor('logout'));
        }

        return $response;
    }
}