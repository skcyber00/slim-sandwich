<?php
/**
 * Created by PhpStorm.
 * User: yann
 * Date: 26/12/17
 * Time: 15:42
 */

namespace lbs\control;


use lbs\control\middleware\TokenControl;
use lbs\model\Card;
use lbs\model\Commande;
use lbs\model\Paiement;
use lbs\model\Sandwich;
use lbs\model\Tarif;
use lbs\control\Pagination;
use lbs\model\Item;
use Ramsey\Uuid\Uuid;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;



class CommandeController
{
    // Récupération du conteneur de dépendance
    /**
     * @var \Slim\Container
     */
    private $container;

    /**
     * CommandeController constructor.
     * @param \Slim\Container $container
     */
    public function __construct(\Slim\Container $container){
      $this->container = $container;
      $this->result = array();
    }

    /*
     * Création d'une commande via une requête POST
     * Renvoie $resp : tableau représentant la commande
     *
     * */
    // todo: ajouter le controle des champs
    /**
     * @param Request $req
     * @param Response $resp
     * @return Response|static
     */
    public function createCommande(Request $req, Response $resp) {
        // Récupération des données envoyées

      $tab = $req->getParsedBody();
      $commande = new Commande();
        // id globalement unique - unicité très probable
      $commande->id = Uuid::uuid1();
      $commande->nom_client = filter_var($tab["nom_client"],FILTER_SANITIZE_STRING);
      $commande->prenom_client = filter_var($tab["prenom_client"],FILTER_SANITIZE_STRING);
      $commande->mail_client = filter_var($tab["mail_client"],FILTER_SANITIZE_EMAIL);
      $livraison = new \DateTime($tab['date']);
      $livraison ->format('Y-m-d H:i:s');
      $commande->livraison = $livraison;
        // Création du token
      $commande->token = bin2hex(openssl_random_pseudo_bytes(32));
      $commande->etat = "non traité";

      $commande->save();

      $cardId = $req->getAttribute('card');
      if(!is_null($cardId)){
        $commande->card()->associate(Card::find($cardId));
      }

      $commande->save();


      try{

        $resp = $resp->withHeader('location',$this->container['router']->pathFor('commande',['id' => $commande->id]));
        $resp = $resp->withHeader('Content-Type', 'application/json')->withStatus(201);
        $resp->getBody()->write(json_encode($commande->toArray()));
        return $resp;

      } catch (\Exception $e){
            // revoyer erreur format jsno
        $resp = $resp->withHeader('Content-Type','application/json')->withStatus(500);
        $resp->getBody()->write(json_encode(['type' => 'error', 'error' => 500, 'message' => $e->getMessage()]));

      }
    }

    /**
     * @param Request $req
     * @param Response $resp
     * @param $args
     * @return Response|static
     */
    public function getCommandes (Request $req, Response $resp, $args) {
      $query = Commande::all();

      return Writer::json_output($resp,200,$query);
    }

    /**
     * @param Request $req
     * @param Response $resp
     * @param $args
     * @return Response|static
     * @throws \Interop\Container\Exception\ContainerException
     */
    public function getState (Request $req, Response $resp, $args) {
     try{

      if($commande = Commande::select("etat")->where('id',"=",$args['id'])->firstOrFail())
        {


          return Writer::json_output($resp,200,$commande);

        } else {
          throw new ModelNotFoundException($req, $resp);
        }

      } catch (ModelNotFoundException $exception){

        $notFoundHandler = $this->container->get('notFoundHandler');
        return $notFoundHandler($req,$resp);
      }
    }

    /**
     * @param Request $req
     * @param Response $resp
     * @param $args
     * @return Response|static
     * @throws \Interop\Container\Exception\ContainerException
     */
    public function getCommande (Request $req, Response $resp, $args) {

      $token = $req->getAttribute("token");

        // SOIT DANS L'URL SOIT DANS L'ENTETE HTTP

      try{

        $requeteBase = Commande::where("id","=", $args['id'])->where("token","=",$token)->firstOrFail();
        $resp = $resp->withHeader('Content-Type', 'application/json')->withStatus(200);
        $resp->getBody()->write(json_encode($requeteBase));
        return $resp;

      } catch (ModelNotFoundException $exception){

        $notFoundHandler = $this->container->get('notFoundHandler');
        return $notFoundHandler($req,$resp);

      }

    }

    /**
     * @param Request $req
     * @param Response $resp
     * @param $args
     * @return Response|static
     */
    public function editCommande(Request $req, Response $resp, $args) {
     $token = $req->getAttribute("token");
     try {
      $commande = Commande::where("id","=",$args["id"])->where("token","=",$token)->firstOrFail();
      $tab = $req->getParsedBody();
      $commande->nom_client = filter_var($tab["nom_client"],FILTER_SANITIZE_STRING);
      $commande->prenom_client= filter_var($tab["prenom_client"],FILTER_SANITIZE_STRING);
      $commande->mail_client = filter_var($tab["mail_client"],FILTER_SANITIZE_EMAIL);
      $commande->livraison = $tab["livraison"];
    } catch (ModelNotFoundException $exception){

      $notFoundHandler = $this->container->get('notFoundHandler');
      return $notFoundHandler($req,$resp);
    }
    

    try{

      $commande->save();

    }catch (\Exception $e){
            // revoyer erreur format jsno
      $resp = $resp->withHeader('Content-Type','application/json');
      $resp->getBody()->write(json_encode(['type' => 'error', 'error' => 500, 'message' => $e->getMessage()]));

    }
    $resp = $resp->withHeader('location',$this->container['router']->pathFor('commande',['token' => $commande->token]));
    $resp = $resp->withHeader('Content-Type', 'application/json')->withStatus(200);
    $resp->getBody()->write(json_encode($commande->toArray()));
    return $resp;
  }

    /**
     * @param Request $req
     * @param Response $resp
     * @param $args
     * @return Response|static
     * @throws \Interop\Container\Exception\ContainerException
     */
    public function getFacture (Request $req, Response $resp, $args) {
     $token = $req->getAttribute("token");
     try{

      if($commande = Commande::Select("nom_client","prenom_client","etat")->where('etat','=','paid')->where('id',"=",$args['id'])->where("token","=",$token)->firstOrFail())
        {
          $somme = 0;
                // si j'obtient la commande
          array_push($this->result,$commande);

                // je veux la liste des items de la commande
          $items = Item::where("commande_id","=",$args['id'])->with('sandwich','size')->get();

          foreach ($items as $item){

            $prix = Tarif::select("prix")->where("taille_id","=",$item->taille_id)->where("sand_id","=",$item->sand_id)->first();

            $tabItem = array('item' => $item->sandwich->nom,'taille id' => $item->taille_id,'quantite' => $item ->quantite, 'prix unitaire' => $prix->prix,'prix items' => $item->quantite * $prix->prix);
            $somme += $item->quantite * $prix->prix;

            array_push($this->result,$tabItem);
          }
          array_push($this->result,array("prix total" => $somme));
          return Writer::json_output($resp,200,$this->result);

        } else {

          throw new ModelNotFoundException($req, $resp);
        }

      } catch (ModelNotFoundException $exception){

        $notFoundHandler = $this->container->get('notFoundHandler');
        return $notFoundHandler($req,$resp);

      }

    }

    /**
     * @param Request $req
     * @param Response $resp
     * @param $args
     * @return Response|static
     * @throws \Interop\Container\Exception\ContainerException
     */
    public function payCommande (Request $req, Response $resp, $args) {
      $token = $req->getAttribute("token");
      try{

        if($commande = Commande::where('id',"=",$args['id'])->where("token","=",$token)->firstOrFail())
          {
            $tab = $req->getParsedBody();
            $paiement = new Paiement();
            $paiement->id = Uuid::uuid1();

            $paiement->commande_id = $commande->id;
            $paiement->carte_bancaire = filter_var($tab["carte_bancaire"],FILTER_SANITIZE_NUMBER_FLOAT);
            $date_expiration = \DateTime::createFromFormat ('m-y',$tab['date_expiration']);

            $paiement->date_expiration = $date_expiration->format('m-y');
            $paiement->save();
            $now = date ('Y-m-d');
            $commande->etat = "paid";
            $commande->date_paiement = $now;
            $commande->save();
            return Writer::json_output($resp,201,$paiement);
          } else {

            throw new ModelNotFoundException($req, $resp);
          }

        } catch (ModelNotFoundException $exception){

          $notFoundHandler = $this->container->get('notFoundHandler');
          return $notFoundHandler($req,$resp);

        }

      }
    }