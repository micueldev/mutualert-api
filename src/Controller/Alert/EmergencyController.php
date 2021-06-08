<?php

namespace App\Controller\Alert;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\CRUD\Read;
use App\Service\Util\Constante;
use App\Service\Util\Jwt;
use App\Service\EmergencyService;
use App\Service\Api\SocketService;
use App\Entity\Alert\Emergency;
use App\Model\Object\Point;

class EmergencyController extends AbstractController
{
    public function list(Request $request, Jwt $jwt, EmergencyService $emergencyService): Response
    {
        try{
            $decoded = $jwt->decodeToken($request->headers->get('token'));
            if(!$decoded['success']) return $this->json($decoded, Constante::HTTP_UNAUTHORIZED);
            $user = $decoded['data'];

            $emergencys = $emergencyService->list($user['id']);
            $data = [];
            foreach($emergencys as $emergency){
                $data = $emergency->asArray(['id', 'apepat', 'apemat', 'nombres', 'avatar', 'phone', 'startedAt']);
                $aLocation = $emergency->getALocation();
                $lastLocation = end($aLocation);
                $data['location'] = ['latitude'=>$lastLocation['latitude'], 'longitude'=>$lastLocation['longitude'], 'accuracy'=>$lastLocation['accuracy']];
            }
            return $this->json(['success'=>true, 'data'=>$data]);

        }catch (\Exception $e){
            return $this->json(['success'=>false,
                                'msg'=>$e->getMessage()],
                Constante::HTTP_SERVER_ERROR);
        }
    }

    public function start(Request $request, Read $read, Jwt $jwt, EmergencyService $emergencyService, SocketService $socketService): Response
    {
        try{
            $decoded = $jwt->decodeToken($request->headers->get('token'), true);
            if(!$decoded['success']) return $this->json($decoded, Constante::HTTP_UNAUTHORIZED);
            $user = $decoded['data'];

            $cadena = 'latitude,longitude,accuracy';
            $sentencia = $read->getData($cadena);
            eval($sentencia);
            if(!$existen){
                return $this->json(['success'=>false,
                                    'msg'=>'No se encontro al parametro ('.$faltante.')'],
                    Constante::HTTP_BAD_REQUEST);
            }
            
            $state = $user->getState();
            if( $state->getInAlert() ){
                return $this->json(['success'=>false,
                                    'msg'=>'El modo emergencia ya está activado'],
                    Constante::HTTP_CONFLICT);
            }

            $date = date_create();
            $this->getDoctrine()->getConnection()->beginTransaction();
            
            $state = $user->getState();
            $state->setGeoLocation(new Point($latitude, $longitude));
            $state->setAccuracyLocation($accuracy);
            $state->setInAlert(true);
            $this->getDoctrine()->getManager()->persist($state);

            $emergency = new Emergency();
            $emergency->setIdUser($user);
            $emergency->setStartedAt($date);
            $emergency->setALocation([[
                'latitude'=>$latitude,
                'longitude'=>$longitude,
                'accuracy'=>$accuracy,
                'date'=>$date
            ]]);
            $this->getDoctrine()->getManager()->persist($emergency);

            $this->getDoctrine()->getManager()->flush();
            $this->getDoctrine()->getConnection()->commit();

            $idsUser = $emergencyService->getIdsUser($state,
                $user->getConfig(),$user->getContacts(), $user->getId(), $user->getUsername());

            $emergency->setAUserAlert($idsUser);
            $this->getDoctrine()->getManager()->persist($emergency);
            $this->getDoctrine()->getManager()->flush();
                        
            $data = $emergency->asArray(['id', 'apepat', 'apemat', 'nombres', 'avatar', 'phone', 'startedAt']);
            $data['location'] = [
                'latitude'=>$latitude,
                'longitude'=>$longitude,
                'accuracy'=>$accuracy
            ];
            $socketService->send(['id'=>implode(',', $idsUser),
                'event'=>Constante::EVENT_EMEGENCY_INIT, 'data'=>$data]);

            return $this->json(['success'=>true]);

        }catch (\Exception $e){
            return $this->json(['success'=>false,
                                'msg'=>$e->getMessage()],
                Constante::HTTP_SERVER_ERROR);
        }
    }

    public function stop(Request $request, Read $read, Jwt $jwt, SocketService $socketService): Response
    {
        try{
            $decoded = $jwt->decodeToken($request->headers->get('token'), true);
            if(!$decoded['success']) return $this->json($decoded, Constante::HTTP_UNAUTHORIZED);
            $user = $decoded['data'];

            $state = $user->getState();
            if( !$state->getInAlert() ){
                return $this->json(['success'=>false,
                                    'msg'=>'El modo emergencia ya está desactivado'],
                    Constante::HTTP_CONFLICT);
            }

            $this->getDoctrine()->getConnection()->beginTransaction();
            
            $state = $user->getState();
            $state->setInAlert(false);
            $this->getDoctrine()->getManager()->persist($state);

            $emergency = $this->getDoctrine()->getRepository('App:Alert\Emergency')->findOneBy([
                'idUser'=>$user->getId(),
                'isActive'=>true
            ]);
            $emergency->setFinishedAt(date_create());
            $emergency->setIsActive(false);
            $this->getDoctrine()->getManager()->persist($emergency);

            $this->getDoctrine()->getManager()->flush();
            $this->getDoctrine()->getConnection()->commit();

            $socketService->send(['id'=>implode(',', $emergency->getAUserAlert()),
                'event'=>Constante::EVENT_EMEGENCY_END, 'data'=>$emergency->getId()]);

            return $this->json(['success'=>true]);

        }catch (\Exception $e){
            return $this->json(['success'=>false,
                                'msg'=>$e->getMessage()],
                Constante::HTTP_SERVER_ERROR);
        }
    }
}