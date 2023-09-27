<?php

namespace Summer\Kefu\Http\Controllers;

use Illuminate\Routing\Controller;
use Input;
use Response;
use ApplicationException;
use System\Models\File;
use Exception;

class FileController extends Controller
{
    public function upfile(){
        try {
            if (!Input::hasFile('file_data')) {
                throw new ApplicationException('请上传文件');
            }
            $uploadedFile = Input::file('file_data');
            if (!$uploadedFile->isValid()) {
                throw new ApplicationException('文件无效');
            }
            $extension = $uploadedFile->getClientOriginalExtension();
            $extension = strtolower($extension);
            if(!in_array($extension,['jpg','png','gif','jpeg','mp3','mp4','m4a','mov'])){
                throw new ApplicationException('仅支持jpg、png、gif、jpeg的图片格式和mp3,mp4,m4a,mov.您上传的格式:'.$extension);
            }
            $file=new File();
            $file->fromPost($uploadedFile);
            $file->is_public = 1;
            $file->save();
            $result = [
                'id' => $file->id,
                'path' => $file->path,
                'diskPath' => $file->getDiskPath(),
                'extension' => $file->extension,
            ];
            $return=[];
            $return['status']=1;
            $return['code']=1;
            $return['msg']='上传成功';
            $return['data']=$result;
            return response()->json(
                $return
            );
        }catch (Exception $ex) {
            $return=[];
            $return['status']=-1;
            $return['code']=-1;
            $return['msg']=$ex->getMessage();
            return response()->json(
                $return
            );
        }
    }
}
