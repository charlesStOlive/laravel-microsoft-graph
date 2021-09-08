<?php

namespace Dcblogdev\MsGraph\AdminResources;

use Dcblogdev\MsGraph\Facades\MsGraphAdmin;
use Exception;
use Wcli\Wconfig\Models\Settings as WcConfig;
use Backend\Models\User as UserModel;
use GuzzleHttp\Client;

class Files extends MsGraphAdmin
{
    private $userId;
    private $baseFolder;
    private $baseRequest;

    public function userid($userId = null)
    {
        $driveFolder = WcConfig::get('drive_folder');
        //trace_log($driveFolder);
        $userDriveId =  WcConfig::get('drive_account');
        if(!$driveFolder || !$userDriveId) {
            throw new \ApplicationException('La configuration de MsGraph est incomplète, verifiez le compte et le dossier par defaut du drive dans les options');
        } else {
            $this->baseFolder = $driveFolder;
            $this->userId = UserModel::find($userDriveId)->msgraph_id;
            //$this->baseRequest = 'users/'.$this->userId;
            $this->baseRequest = 'sites/notilac.sharepoint.com';

            // $this->baseFolder = $driveFolder;
            // $this->userId = UserModel::find($userDriveId)->msgraph_id;
            // $this->baseRequest = 'sites/notilac.sharepoint.com/drive/root:';
            //$this->baseRequest = 'users/'.$this->userId.'/sites/notilac.sharepoint.com';
            //$this->baseRequest."/drive/root:"

            return $this;
        }
        //$this->userId = $userId;
        
    }

    public function getDrives()
    {
        if ($this->userId == null) {
            throw new Exception("userId is required.");
        }

        return MsGraphAdmin::get($this->baseRequest.'/drives');
    }
    public function getSites()
    {
        if ($this->userId == null) {
            throw new Exception("userId is required.");
        }

        return MsGraphAdmin::get('/sites/root');
    }
    public function site() {
        return MsGraphAdmin::get('/sites/notilac.sharepoint.com');
    }

    public function downloadFile($id)
    {
        if ($this->userId == null) {
            throw new Exception("userId is required.");
        }

        $id = MsGraphAdmin::get($this->baseRequest.'/drive/items/'.$id);

        return redirect()->away($id['@microsoft.graph.downloadUrl']);
    }

    // public function deleteFile($id)
    // {
    //     if ($this->userId == null) {
    //         throw new Exception("userId is required.");
    //     }

    //     return MsGraphAdmin::delete('users/'.$this->userId.'/drive/items/'.$id);
    // }
    public function getItemId($pathOrPathFileName) {
        $pathOrPathFileName = $this->baseFolder.'/'.$pathOrPathFileName;
        $request = $this->baseRequest."/drive/root:".$this->forceStartingSlash($pathOrPathFileName);
        $request =  MsGraphAdmin::get($request, [],  'noBody');
        return $request ?? null;
    }
    public function getChilds($pathName) {
        //Recuperation de l'id du dossier
        $pathName = $this->baseFolder.'/'.$pathName;
        $request = $this->baseRequest."/drive/root:".$this->forceStartingSlash($pathName).":/children";
        return  MsGraphAdmin::get($request, [],  'noBody');
    }
    // public function getSiteChilds() {
    //     //Recuperation de l'id du dossier
    //     $request = $this->baseRequest."/drive/root/children";
    //     return  MsGraphAdmin::get($request, [],  'noBody');
    // }
    public function createFolder($folderName, $pathName = null) {
        //Recuperation de l'id du dossier
        
        $pathComplete = $pathName ? $pathName.'/'.$folderName : $folderName;
        //trace_log("chemin complet : ".$pathComplete);
        $folderId = $this->getItemId($pathComplete)['id'];
        if($folderId) {
            //trace_log("Existe déjà");
            return $folderId;
        } else {
            $creation = $this->upload($pathComplete.'/temp_delete.txt', 'temp');
            $createdId = $creation['id'];
            if($createdId) {
                $this->deleteFile($createdId);
            }
            return  $creation['parentReference']['id'] ?? null;
        }
    }
    public function deleteFile($idFileToDelete) {
        $request = $this->baseRequest."/drive/items/".$idFileToDelete;
        return MsGraphAdmin::delete($request, null, 'noBody');

    }
    public function getFileUrlContent($pathAndFileName) {
        $fileId = $this->getItemId($pathAndFileName)['id'];
        $request = $this->baseRequest."/drive/items/$fileId/content";
        return MsGraphAdmin::get($request, null, 'noDecode');
    }
    public function upload($pathAndFileName, $content) {
        $pathAndFileName = $this->baseFolder.'/'.$pathAndFileName;
        $path_parts = pathinfo($pathAndFileName);
        $filePath = $path_parts['dirname'];
        $fileName = $path_parts['basename'];
        $request = $this->baseRequest."/drive/root:".$this->forceStartingSlash($filePath)."/$fileName:/content?@microsoft.graph.conflictBehavior=rename";
        return MsGraphAdmin::put($request, $content, 'noJson');
    }

    public function uploadBigFile($name, $uploadPath, $path = null)
    {
        $finalPath = $path ? $this->baseFolder.'/'.$path : $this->baseFolder;
        $uploadSession = $this->createUploadSession($name, $finalPath);
        $uploadUrl = $uploadSession['uploadUrl'];

        $fragSize = 320 * 1024;
        $file = file_get_contents($uploadPath);
        //trace_log($uploadPath);
        $fileSize = strlen($file);
        $numFragments = ceil($fileSize / $fragSize);
        $bytesRemaining = $fileSize;
        $i = 0;
        $ch = curl_init($uploadUrl);
        while ($i < $numFragments) {
            $chunkSize = $numBytes = $fragSize;
            $start = $i * $fragSize;
            $end = $i * $fragSize + $chunkSize - 1;
            $offset = $i * $fragSize;
            if ($bytesRemaining < $chunkSize) {
                $chunkSize = $numBytes = $bytesRemaining;
                $end = $fileSize - 1;
            }
            if ($stream = fopen($uploadPath, 'r')) {
                // get contents using offset
                //trace_log($uploadPath);
                $data = stream_get_contents($stream, $chunkSize, $offset);
                fclose($stream);
            }

            $content_range = " bytes " . $start . "-" . $end . "/" . $fileSize;
            $headers = [
                'Content-Length' => $numBytes,
                'Content-Range' => $content_range
            ];

            $client = new Client;
            //trace_log($uploadUrl);
            //trace_log([
            //     'headers' => $headers,
            //     'body' => $data,
            // ]);

            $response = $client->put($uploadUrl, [
                'headers' => $headers,
                'body' => $data,
            ]);

            $bytesRemaining = $bytesRemaining - $chunkSize;
            $i++;
        }

    }

    protected function createUploadSession($name, $path = null)
    {
        $path = $path === null ? $this->baseRequest."/drive/root:/$name:/createUploadSession" : $this->baseRequest."/drive/root:".$this->forceStartingSlash($path)."/$name:/createUploadSession";

        return MsGraphAdmin::post($path, [
            'item' => [
                "@microsoft.graph.conflictBehavior" => "rename",
                "name" => $name
            ]
        ]);
    }

    protected function forceStartingSlash($string)
    {
        if (substr($string, 0, 1) !== "/") {
            $string = "/$string";
        }

        return $string;
    }
}
