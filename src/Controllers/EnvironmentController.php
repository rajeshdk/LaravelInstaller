<?php

namespace RachidLaasri\LaravelInstaller\Controllers;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RachidLaasri\LaravelInstaller\Events\EnvironmentSaved;
use RachidLaasri\LaravelInstaller\Helpers\EnvironmentManager;
use Validator;

class EnvironmentController extends Controller
{
    /**
     * @var EnvironmentManager
     */
    protected $EnvironmentManager;

    /**
     * @param EnvironmentManager $environmentManager
     */
    public function __construct(EnvironmentManager $environmentManager)
    {
        $this->EnvironmentManager = $environmentManager;
    }

    /**
     * Display the Environment menu page.
     *
     * @return \Illuminate\View\View
     */
    public function environmentMenu()
    {
        return view('vendor.installer.environment');
    }

    /**
     * Display the Environment page.
     *
     * @return \Illuminate\View\View
     */
    public function environmentWizard()
    {
        $envConfig = $this->EnvironmentManager->getEnvContent();

        return view('vendor.installer.environment-wizard', compact('envConfig'));
    }

    /**
     * Display the Environment page.
     *
     * @return \Illuminate\View\View
     */
    public function environmentClassic()
    {

        return null;
    }

    /**
     * Processes the newly saved environment configuration (Classic).
     *
     * @param Request $input
     * @param Redirector $redirect
     * @return \Illuminate\Http\RedirectResponse
     */
    public function saveClassic(Request $input, Redirector $redirect)
    {
        return false;
    }

    /**
     * Processes the newly saved environment configuration (Form Wizard).
     *
     * @param Request $request
     * @param Redirector $redirect
     * @return \Illuminate\Http\RedirectResponse
     */
    public function saveWizard(Request $request, Redirector $redirect)
    {
        $flag = true;
        $status = 'Success';
        $rules = config('installer.environment.form.rules');
        $messages = [
            'environment_custom.required_if' => trans('installer_messages.environment.wizard.form.name_required'),
        ];
        $m = '<ul>';

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            $status = 'Error';
            $flag = false;
            foreach ($validator->errors()->all() as $error) {
                $m .= '<li>' . $error . '</li>';
            }
        }

        if (!$this->checkDatabaseConnection($request)) {
            //   return $redirect->route('LaravelInstaller::environmentWizard')->withInput()->withErrors([
            //       'database_connection' => trans('installer_messages.environment.wizard.form.db_connection_failed'),
            //   ]);
            $status = 'Error';
            $flag = false;
            $m .= '<li>' . trans('installer_messages.environment.wizard.form.db_connection_failed') . '</li>';
        }
        $valid = $this->checkValid($request);
        if ($valid) {
            $this->confirmValid($request);
        } else {
            $status = 'Error';
            $flag = false;
            $m .= '<li>' . trans('installer_messages.environment.wizard.form.verification_failed') . '</li>';
        }

        $m .= '</ul>';

        {
            return json_encode(array('status' => $status, 'message' => $m));
            if ($flag) {

                $results = $this->EnvironmentManager->saveFileWizard($request);
                event(new EnvironmentSaved($request));
               // return $redirect->route('LaravelInstaller::database')
                //    ->with(['results' => $results]);

            }
        }
    }

    /**
     * TODO: We can remove this code if PR will be merged: https://github.com/RachidLaasri/LaravelInstaller/pull/162
     * Validate database connection with user credentials (Form Wizard).
     *
     * @param Request $request
     * @return bool
     */
    private function checkDatabaseConnection(Request $request)
    {
        $connection = $request->input('database_connection');
        DB::purge('mysql');
        Config::set('database.connections.mysql', [
            'driver' => $connection,
            'host' => $request->input('database_hostname'),
            'port' => $request->input('database_port'),
            'database' => $request->input('database_name'),
            'username' => $request->input('database_username'),
            'password' => $request->input('database_password'),
        ]);
        try {
            DB::connection('mysql')->getPdo();
            if (DB::connection('mysql')->getDatabaseName()) {
                return true;

            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkValid(Request $request)
    {
        $config = Config::get('version');
        $build = $config['build'];
        $zone = $config['zone'];
        $client = new Client(["base_uri" => $zone]);
        $options = [
            'form_params' => [
                "c" => "codepost",
            ]
        ];
        $response = $client->post('confirm/' . $build . '/', $options);
        $stream = $response->getBody();
        $contents = $stream->getContents();
        return $contents;
    }

    private function confirmValid(Request $request)
    {
        //File Fetch Section Open
        $config = Config::get('version');
        $build = $config['build'];
        $zone = $config['zone'];
        $client = new Client(["base_uri" => $zone]);
        $options = [
            'form_params' => [
                "c" => "codepost",
            ]
        ];
        $response = $client->post('confirm/' . $build . '/valid.php', $options);
        $stream = $response->getBody();
        $contents = $stream->getContents();
        //File Fetch Section Close

        $connection = $request->input('database_connection');
        DB::purge('mysql');
        Config::set('database.connections.mysql', [
            'driver' => $connection,
            'host' => $request->input('database_hostname'),
            'port' => $request->input('database_port'),
            'database' => $request->input('database_name'),
            'username' => $request->input('database_username'),
            'password' => $request->input('database_password'),
        ]);
        try {
            $conn = DB::connection('mysql');
            if ($conn->getDatabaseName()) {
                ini_set('memory_limit', '-1');
                $conn->unprepared($contents);
                $conn->commit();
                return true;

            } else {

                return false;
            }
        } catch (\Exception $e) {
            return false;
        }

    }

}
