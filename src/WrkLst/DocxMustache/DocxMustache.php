<?PHP

namespace WrkLst\DocxMustache;

use Exception;
use Illuminate\Support\Facades\Log;

//Custom DOCX template class to change content based on mustache templating engine.
class DocxMustache
{
    public $items;
    public $word_doc;
    public $template_file_name;
    public $template_file;
    public $local_path;
    public $storageDisk;
    public $storagePathPrefix;
    public $zipper;
    public $imageManipulation;
    public $verbose;

    public function __construct($items, $local_template_file)
    {
        $this->items = $items;
        $this->template_file_name = basename($local_template_file);
        $this->template_file = $local_template_file;
        $this->word_doc = false;
        $this->zipper = new \Chumper\Zipper\Zipper;

        //name of disk for storage
        $this->storageDisk = 'local';

        //prefix within your storage path
        $this->storagePathPrefix = 'app/';

        //if you use img urls that support manipulation via parameter
        $this->imageManipulation = ''; //'&w=1800';

        $this->verbose = false;
    }

    public function execute()
    {
        $this->copyTmplate();
        $this->readTeamplate();
    }

    /**
     * @param string $file
     */
    public function storagePath($file)
    {
        return storage_path($file);
    }

    /**
     * @param string $msg
     */
    protected function log($msg)
    {
        //introduce logging method here to keep track of process
        // can be overwritten in extended class to log with custom preocess logger
        if ($this->verbose) {
            Log::error($msg);
        }
    }

    public function cleanUpTmpDirs()
    {
        $now = time();
        $isExpired = ($now - (60 * 240));
        $disk = \Storage::disk($this->storageDisk);
        $all_dirs = $disk->directories($this->storagePathPrefix.'DocxMustache');
        foreach ($all_dirs as $dir) {
            //delete dirs older than 20min
            if ($disk->lastModified($dir) < $isExpired)
            {
                $disk->deleteDirectory($dir);
            }
        }
    }

    public function getTmpDir()
    {
        $this->cleanUpTmpDirs();
        $path = $this->storagePathPrefix.'DocxMustache/'.uniqid($this->template_file).'/';
        \File::makeDirectory($this->storagePath($path), 0775, true);
        return $path;
    }

    public function copyTmplate()
    {
        $this->log('Get Copy of Template');
        $this->local_path = $this->getTmpDir();
        \Storage::disk($this->storageDisk)->copy($this->storagePathPrefix.$this->template_file, $this->local_path.$this->template_file_name);
    }

    protected function exctractOpenXmlFile($file)
    {
        $this->zipper->make($this->storagePath($this->local_path.$this->template_file_name))
            ->extractTo($this->storagePath($this->local_path), array($file), \Chumper\Zipper\Zipper::WHITELIST);
    }

    protected function ReadOpenXmlFile($file, $type="file")
    {
        $this->exctractOpenXmlFile($file);

        if($type=="file")
        {
            if ($file_contents = \Storage::disk($this->storageDisk)->get($this->local_path.$file))
            {
                return $file_contents;
            } else
            {
                throw new Exception('Cannot not read file '.$file);
            }
        } else
        {
            if ($xml_object = simplexml_load_file($this->storagePath($this->local_path.$file)))
            {
                return $xml_object;
            } else
            {
                throw new Exception('Cannot load XML Object from file '.$file);
            }
        }
    }

    protected function saveOpenXmlFile($file, $folder, $content)
    {
        \Storage::disk($this->storageDisk)
            ->put($this->local_path.$file, $content);
        //add new content to word doc
        $this->zipper->folder($folder)
            ->add($this->storagePath($this->local_path.$file));
    }

    public function readTeamplate()
    {
        $this->log('Analyze Template');
        //get the main document out of the docx archive
        $this->word_doc = $this->ReadOpenXmlFile('word/document.xml','file');

        $this->log('Merge Data into Template');

        $this->word_doc = MustacheRender::render($this->items, $this->word_doc);

        $this->word_doc = HtmlConversion::convert($this->word_doc);

        $this->ImageReplacer();

        $this->log('Compact Template with Data');

        $this->saveOpenXmlFile('word/document.xml', 'word', $this->word_doc);
        $this->zipper->close();
    }

    protected function AddContentType($imageCt = "jpeg")
    {
        $ct_file = $this->ReadOpenXmlFile('[Content_Types].xml','object');

        if (!($ct_file instanceof \Traversable)) {
            throw new Exception('Cannot traverse through [Content_Types].xml.');
        } else
        {
            //check if content type for jpg has been set
            $i = 0;
            $ct_already_set = false;
            foreach ($ct_file as $ct)
            {
                if ((string) $ct_file->Default[$i]['Extension'] == $imageCt) {
                                $ct_already_set = true;
                }
                $i++;
            }

            //if content type for jpg has not been set, add it to xml
            // and save xml to file and add it to the archive
            if (!$ct_already_set)
            {
                $sxe = $ct_file->addChild('Default');
                $sxe->addAttribute('Extension', $imageCt);
                $sxe->addAttribute('ContentType', 'image/'.$imageCt);

                if ($ct_file_xml = $ct_file->asXML())
                {
                    $this->SaveOpenXmlFile('[Content_Types].xml', false, $ct_file_xml);
                } else
                {
                    throw new Exception('Cannot generate xml for [Content_Types].xml.');
                }
            }
        }
    }

    protected function FetchReplaceableImages(&$main_file, $ns)
    {
        //set up basic arrays to keep track of imgs
        $imgs = array();
        $imgs_replaced = array(); // so they can later be removed from media and relation file.
        $newIdCounter = 1;

        //iterate through all drawing containers of the xml document
        foreach ($main_file->xpath('//w:drawing') as $k=>$drawing)
        {
            $ueid = "wrklstId".$newIdCounter;
            $wasId = (string) $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->children($ns['a'])->graphic->graphicData->children($ns['pic'])->pic->blipFill->children($ns['a'])->blip->attributes($ns['r'])["embed"];
            $imgs_replaced[$wasId] = $wasId;
            $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->children($ns['a'])->graphic->graphicData->children($ns['pic'])->pic->blipFill->children($ns['a'])->blip->attributes($ns['r'])["embed"] = $ueid;

            $cx = (int) $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->children($ns['a'])->graphic->graphicData->children($ns['pic'])->pic->spPr->children($ns['a'])->xfrm->ext->attributes()["cx"];
            $cy = (int) $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->children($ns['a'])->graphic->graphicData->children($ns['pic'])->pic->spPr->children($ns['a'])->xfrm->ext->attributes()["cy"];

            //figure out if there is a URL saved in the description field of the img
            $img_url = $this->analyseImgUrlString((string) $drawing->children($ns['wp'])->xpath('wp:docPr')[0]->attributes()["descr"]);
            $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->xpath('wp:docPr')[0]->attributes()["descr"] = $img_url["rest"];

            //check https://startbigthinksmall.wordpress.com/2010/01/04/points-inches-and-emus-measuring-units-in-office-open-xml/
            // for EMUs calculation
            /*
            295px @72 dpi = 1530350 EMUs = Multiplier for 72dpi pixels 5187.627118644067797
            413px @72 dpi = 2142490 EMUs = Multiplier for 72dpi pixels 5187.627118644067797

            */

            //if there is a url, save this img as a img to be replaced
            if (trim($img_url["url"]))
            {
                $imgs[] = array(
                    "cx" => $cx,
                    "cy" => $cy,
                    "width" => (int) ($cx / 5187.627118644067797),
                    "height" => (int) ($cy / 5187.627118644067797),
                    "wasId" => $wasId,
                    "id" => $ueid,
                    "url" => $img_url["url"],
                );

                $newIdCounter++;
            }
        }
        return array(
            'imgs' => $imgs,
            'imgs_replaced' => $imgs_replaced
        );
    }

    protected function RemoveReplaceImages($imgs_replaced, &$rels_file)
    {
        //iterate through replaced images and clean rels files from them
        foreach ($imgs_replaced as $img_replaced)
        {
            $i = 0;
            foreach ($rels_file as $rel)
            {
                if ((string) $rel->attributes()['Id'] == $img_replaced)
                {
                    $this->zipper->remove('word/'.(string) $rel->attributes()['Target']);
                    unset($rels_file->Relationship[$i]);
                }
                $i++;
            }
        }
    }

    protected function InsertImages($ns, &$imgs, &$rels_file, &$main_file)
    {
        $docimage = new DocImage();

        //define what images are allowed
        $allowed_imgs = $docimage->AllowedContentTypeImages();

        //iterate through replacable images
        foreach ($imgs as $k=>$img)
        {
            //get file type of img and test it against supported imgs
            if ($imgageData = $docimage->GetImageFromUrl($img['url'], $this->imageManipulation))
            {
                $imgs[$k]['img_file_src'] = str_replace("wrklstId", "wrklst_image", $img['id']).$allowed_imgs[$imgageData['mime']];
                $imgs[$k]['img_file_dest'] = str_replace("wrklstId", "wrklst_image", $img['id']).'.jpeg';

                $resampled_img = $docimage->ResampleImage($this, $imgs, $k, $imgageData['data']);

                $sxe = $rels_file->addChild('Relationship');
                $sxe->addAttribute('Id', $img['id']);
                $sxe->addAttribute('Type', "http://schemas.openxmlformats.org/officeDocument/2006/relationships/image");
                $sxe->addAttribute('Target', "media/".$imgs[$k]['img_file_dest']);

                //update height and width of image in document.xml
                $new_height_emus = (int) ($resampled_img['height'] * 5187.627118644067797);
                $new_width_emus = (int) ($resampled_img['width'] * 5187.627118644067797);

                foreach ($main_file->xpath('//w:drawing') as $k=>$drawing)
                {
                    if ($img['id'] == $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->children($ns['a'])
                        ->graphic->graphicData->children($ns['pic'])->pic->blipFill->children($ns['a'])
                        ->blip->attributes($ns['r'])["embed"])
                    {
                        $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->children($ns['a'])
                            ->graphic->graphicData->children($ns['pic'])->pic->spPr->children($ns['a'])
                            ->xfrm->ext->attributes()["cx"] = $new_width_emus;
                        $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->children($ns['a'])
                            ->graphic->graphicData->children($ns['pic'])->pic->spPr->children($ns['a'])
                            ->xfrm->ext->attributes()["cy"] = $new_height_emus;

                        //the following also changes the contraints of the container for the img.
                        // probably not wanted, as this will make images larger than the constraints of the placeholder
                        /*
                        $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->inline->extent->attributes()["cx"] = $new_width_emus;
                        $main_file->xpath('//w:drawing')[$k]->children($ns['wp'])->inline->extent->attributes()["cy"] = $new_height_emus;
                        */
                        break;
                    }
                }
            }
        }
    }

    protected function ImageReplacer()
    {
        $this->log('Merge Images into Template');

        //load main doc xml
        $main_file = simplexml_load_string($this->word_doc);

        //get all namespaces of the document
        $ns = $main_file->getNamespaces(true);

        $replaceableImage = $this->FetchReplaceableImages($main_file, $ns);
        $imgs = $replaceableImage['imgs'];
        $imgs_replaced = $replaceableImage['imgs_replaced'];

        $rels_file = $this->ReadOpenXmlFile('word/_rels/document.xml.rels','object');

        $this->RemoveReplaceImages($imgs_replaced, $rels_file);

        //add jpg content type if not set
        $this->AddContentType('jpeg');

        $this->InsertImages($ns, $imgs, $rels_file, $main_file);

        if ($rels_file_xml = $rels_file->asXML())
        {
            $this->SaveOpenXmlFile('word/_rels/document.xml.rels', 'word/_rels', $rels_file_xml);
        } else
        {
            throw new Exception('Cannot generate xml for word/_rels/document.xml.rels.');
        }

        if ($main_file_xml = $main_file->asXML())
        {
            $this->word_doc = $main_file_xml;
        } else
        {
            throw new Exception('Cannot generate xml for word/document.xml.');
        }
    }

    /**
     * @param string $string
     */
    protected function analyseImgUrlString($string)
    {
        $start = "[IMG-REPLACE]";
        $end = "[/IMG-REPLACE]";
        $string = ' '.$string;
        $ini = strpos($string, $start);
        if ($ini == 0)
        {
            $url = '';
            $rest = $string;
        } else
        {
            $ini += strlen($start);
            $len = ((strpos($string, $end, $ini)) - $ini);
            $url = substr($string, $ini, $len);

            $ini = strpos($string, $start);
            $len = strpos($string, $end, $ini + strlen($start)) + strlen($end);
            $rest = substr($string, 0, $ini).substr($string, $len);
        }
        return array(
            "url" => $url,
            "rest" => $rest,
        );
    }

    public function saveAsPdf()
    {
        $this->log('Converting DOCX to PDF');
        //convert to pdf with libre office
        $command = "soffice --headless --convert-to pdf ".$this->storagePath($this->local_path.$this->template_file_name).' --outdir '.$this->storagePath($this->local_path);
        $process = new \Symfony\Component\Process\Process($command);
        $process->start();
        while ($process->isRunning()) {
            //wait until process is ready
        }
        // executes after the command finishes
        if (!$process->isSuccessful()) {
            throw new \Symfony\Component\Process\Exception\ProcessFailedException($process);
        } else
        {
            $path_parts = pathinfo($this->storagePath($this->local_path.$this->template_file_name));
            return $this->storagePath($this->local_path.$path_parts['filename'].'pdf');
        }
    }
}
