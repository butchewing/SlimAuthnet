<?php
/**
 * A neat little thingy to process credit cards with authorize.net
 *
 * PHP version 5
 *
 * @category API
 * @package  SlimAuthnet
 * @author   Butch Ewing <butch@butchewing.com>
 * @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 * @link     http://pear.php.net/package/PackageName
 */

// Load Composer Packages.
require '../vendor/autoload.php';
use \Slim\Middleware\HttpBasicAuthentication\PdoAuthenticator;

// Load Environmental Variables.
$dotenv = new Dotenv\Dotenv('../');
$dotenv->load();


// Prepare app.
$app = new \Slim\Slim(array('templates.path' => '../templates'));
$app->config('debug', getenv('DEBUG'));


/**
 * GetDB Function
 *
 * Instantiate the database connection
 *
 * @return (type)
 */
function getDB()
{
  $dbhost = getenv('DB_HOST');
  $dbuser = getenv('DB_USER');
  $dbpass = getenv('DB_PASS');
  $dbname = getenv('DB_NAME');

  $mysql_conn_string = "mysql:host=$dbhost;dbname=$dbname";
  $dbConnection      = new PDO($mysql_conn_string, $dbuser, $dbpass);
  $dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $dbConnection;

}//end getDB()


$app->add(
  new \Slim\Middleware\HttpBasicAuthentication(
    array(
      "path"          => "/admin",
      "realm"         => "Here be dragons.",
      "authenticator" => new PdoAuthenticator([
        "pdo"         => getDB()
      ]),
      "error"         => function ($arguments) use ($app) {
        $response["status"]  = "error";
        $response["message"] = $arguments["message"];
        $app->response->write(json_encode($response, JSON_UNESCAPED_SLASHES));
      }
    )
  )
);


// Create monolog logger and store logger in container as singleton
// (Singleton resources retrieve the same log resource definition each time)
$app->container->singleton('log', function () {
  $log = new \Monolog\Logger('slim-skeleton');
  $log->pushHandler(new \Monolog\Handler\StreamHandler('../logs/app.log', \Monolog\Logger::DEBUG));
  return $log;
});


// Start Authentication
$app->add(new \Slim\Middleware\JwtAuthentication([
  "path"     => "/api",
  "logger"   => $logger,
  "secret"   => getenv('SECRET_KEY'),
  "callback" => function($options) use($app) {
    $app->jwt = $options["decoded"];
  }
]));


// Prepare view
$app->view(new \Slim\Views\Twig());
$app->view->parserOptions = array(
  'charset'          => 'utf-8',
  'cache'            => realpath('../templates/cache'),
  'auto_reload'      => true,
  'strict_variables' => false,
  'autoescape'       => true
);
$app->view->parserExtensions = array(new \Slim\Views\TwigExtension());


// Home
$app->get('/', function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Home '/' route");
  $app->render('index.html');
});


$app->get("/admin", function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Admin '/admin' route");
  global $base_url;

  $app->redirect('/admin/clients');
});


$app->get("/admin/clients", function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Admin '/admin/clients' route");
  global $base_url;

  try
  {
    $db  = getDB();
    $sth = $db->prepare("SELECT *
                      FROM clients");
    $sth->execute();
    $clients = $sth->fetchAll();

    if ($clients) {
      $params = array(
        'data'     => $clients,
        'title'    => 'Clients',
        'base_url' => $base_url,
        'page'     => 'clients'
      );
      $app->render('admin_clients.html', $params);
    } else {
      throw new PDOException('No records found.');
    }

  } catch(PDOException $e) {
    $app->response()->setStatus(404);
    echo '{"error":{"text":'. $e->getMessage() .'}}';
  }//end try

});


$app->get("/admin/clients/add", function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Admin '/admin/clients/add' route");
  global $base_url;

  $params = array(
    'title'    => 'Add Client',
    'base_url' => $base_url,
    'page'     => 'clients'
  );
  $app->render('admin_clients_add.html', $params);
});


$app->post("/admin/clients/add", function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Post Admin '/admin/clients/add' route");

  // Params.
  $allPostVars             = $app->request->post();
  $name                    = $allPostVars['name'];
  $token_id                = $allPostVars['token_id'];
  $authnet_api_login_id    = $allPostVars['authnet_api_login_id'];
  $authnet_transaction_key = $allPostVars['authnet_transaction_key'];

  // Generate JWT.
  $key   = getenv('SECRET_KEY');
  $token = array(
    "jti"  => $token_id,
    "iat"  => time()
  );
  $token = JWT::encode($token, $key);

  // Insert Client.
  try
  {
    $db  = getDB();
    $sth = $db->prepare("INSERT INTO clients
              (name, token_id, token, authnet_api_login_id, authnet_transaction_key, status, created_at)
              VALUES
              (:name, :token_id, :token, :authnet_api_login_id, :authnet_transaction_key, :status, now())");
    $sth->execute(
      array(
        ':name'                    => $name,
        ':token_id'                => $token_id,
        ':token'                   => $token,
        ':authnet_api_login_id'    => $authnet_api_login_id,
        ':authnet_transaction_key' => $authnet_transaction_key,
        ':status'                  => 1
      )
    );

    $db = null;

    $app->redirect('/admin/clients');

  } catch(PDOException $e) {
    $app->response()->setStatus(404);
    echo '{"error":{"text":'. $e->getMessage() .'}}';
  }//end try
});


$app->get("/admin/clients/:id", function($id) {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Admin '/admin/clients/:id' route");
  global $base_url;

  try
  {
    $db  = getDB();
    $sth = $db->prepare("SELECT *
                  FROM clients
                  WHERE id = :id");
    $sth->bindParam(':id', $id, PDO::PARAM_INT);
    $sth->execute();
    $client = $sth->fetchAll();

    if ($client) {
      $params = array(
        'data'     => $client,
        'title'    => 'Client',
        'base_url' => $base_url,
        'page'     => 'clients'
      );
      $app->render('admin_clients_edit.html', $params);
    } else {
      throw new PDOException('No records found.');
    }

    $db = null;

  } catch(PDOException $e) {
    $app->response()->setStatus(404);
    echo '{"error":{"text":'. $e->getMessage() .'}}';
  }//end try

});


$app->post("/admin/clients/:id", function($id) {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Post Admin '/admin/clients/:id' route");

  // Params.
  $allPostVars             = $app->request->post();
  $name                    = $allPostVars['name'];
  $authnet_api_login_id    = $allPostVars['authnet_api_login_id'];
  $authnet_transaction_key = $allPostVars['authnet_transaction_key'];
  $status                  = $allPostVars['status'];

  // Insert Client.
  try
  {
    $db  = getDB();
    $sth = $db->prepare("UPDATE clients
              SET name = :name,
                  authnet_api_login_id = :authnet_api_login_id,
                  authnet_transaction_key = :authnet_transaction_key,
                  status = :status
              WHERE id = $id");
    $sth->execute(
      array(
        ':name'                    => $name,
        ':authnet_api_login_id'    => $authnet_api_login_id,
        ':authnet_transaction_key' => $authnet_transaction_key,
        ':status'                  => $status
      )
    );

    $db = null;

    $app->redirect('/admin/clients');

  } catch(PDOException $e) {
    $app->response()->setStatus(404);
    echo '{"error":{"text":'. $e->getMessage() .'}}';
  }//end try
});


$app->delete("/admin/clients", function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Delete Admin '/admin/clients' route");

  // Params.
  $allPostVars = $app->request->post();
  $id          = $allPostVars['client_id'];

  try
  {
    $db  = getDB();
    $sth = $db->prepare("DELETE
                      FROM clients
                      WHERE id = $id
                      LIMIT 1");
    $sth->execute();
    $db = null;

    $app->redirect('/admin/clients');
  } catch(PDOException $e) {
    $app->response()->setStatus(404);
    echo '{"error":{"text":'. $e->getMessage() .'}}';
  }//end try
});


$app->get("/admin/users", function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Admin '/admin/users' route");
  global $base_url;

  try
  {
    $db  = getDB();
    $sth = $db->prepare("SELECT *
                      FROM users");
    $sth->execute();
    $users = $sth->fetchAll();

    if ($users) {
      $params = array(
        'data'     => $users,
        'title'    => 'Users',
        'base_url' => $base_url,
        'page'     => 'users'
      );
      $app->render('admin_users.html', $params);
    } else {
      throw new PDOException('No records found.');
    }

    $db = null;
  } catch(PDOException $e) {
    $app->response()->setStatus(404);
    echo '{"error":{"text":'. $e->getMessage() .'}}';
  }//end try

});

$app->get("/admin/users/add", function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Admin '/admin/clients/add' route");
  global $base_url;

  $params = array(
    'title'    => 'Add User',
    'base_url' => $base_url,
    'page'     => 'users'
  );
  $app->render('admin_users_add.html', $params);
});

$app->post("/admin/users/add", function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Post Admin '/admin/users/:id' route");

  // Params.
  $allPostVars = $app->request->post();
  $user        = $allPostVars['username'];
  $password    = $allPostVars['password'];
  $hash        = password_hash($password, PASSWORD_DEFAULT);

  // Insert Client.
  try
  {
    $db  = getDB();
    $sth = $db->prepare("INSERT INTO users
              (user, hash)
              VALUES
              (:user, :hash)
           ");
    $sth->execute(
      array(
        ':user' => $user,
        ':hash' => $hash
      )
    );

    $db = null;

    $app->redirect('/admin/users');

  } catch(PDOException $e) {
    $app->response()->setStatus(404);
    echo '{"error":{"text":'. $e->getMessage() .'}}';
  }//end try
});

$app->delete("/admin/users", function() {
  $app = \Slim\Slim::getInstance();
  //$app->log->info("Delete Admin '/admin/users' route");

  // Params.
  $allPostVars = $app->request->post();
  $id          = $allPostVars['user_id'];

  try
  {
    $db  = getDB();
    $sth = $db->prepare("DELETE
                      FROM users
                      WHERE id = $id
                      LIMIT 1");
    $sth->execute();
    $db = null;

    $app->redirect('/admin/users');
  } catch(PDOException $e) {
    $app->response()->setStatus(404);
    echo '{"error":{"text":'. $e->getMessage() .'}}';
  }//end try
});


/**
 * AIM Function
 *
 * Process the credit card transaction using the Advanced Integration Method
 *
 * @param (string) $client The Client reference
 *
 * @return (json)
 */
$app->post('/api/aim', function() {
  $app = \Slim\Slim::getInstance();

  try
  {
    $db  = getDB();
    $sth = $db->prepare("SELECT *
                FROM clients
                WHERE token_id = :id");
    $sth->bindParam(':id', $app->jwt->jti, PDO::PARAM_INT);
    $sth->execute();
    $client = $sth->fetch(PDO::FETCH_OBJ);

    if ($client) {
      $app->response->setStatus(200);
      $app->response()->headers->set('Content-Type', 'application/json');
      //echo json_encode($client);

      $allPostVars = $app->request->post();
      $amount      = $allPostVars['amount'];
      $card_num    = $allPostVars['card_num'];
      $exp_date    = $allPostVars['exp_date'];

      if (isset($amount, $card_num, $exp_date)) {
        define("AUTHORIZENET_API_LOGIN_ID", $client->authnet_api_login_id);
        define("AUTHORIZENET_TRANSACTION_KEY", $client->authnet_transaction_key);
        //define("AUTHORIZENET_SANDBOX", true);

        $sale           = new AuthorizeNetAIM;
        $sale->amount   = $amount;
        $sale->card_num = $card_num;
        $sale->exp_date = $exp_date;
        $response       = $sale->authorizeAndCapture();
        if ($response->approved) {
          echo json_encode("Success! Transaction ID:" . $response->transaction_id);
        } else {
          echo json_encode("ERROR:" . $response->error_message);
        }
      }

      $db = null;
    } else {
      throw new PDOException('No records found.');
    }

  } catch(PDOException $e) {
    $app->response()->setStatus(404);
    echo '{"error":{"text":'. $e->getMessage() .'}}';
  }//end try

});


// Run app
$app->run();
