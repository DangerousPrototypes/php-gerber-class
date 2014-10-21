<?php
//todo: clean up layers creation, reduce file loads
// image layers not quite aligned 
//gerbv spits out images with non-requested colors...
//animate between two versions
//an example of how to use the class with gerber .zip upload form
//usage example:
//require_once('gerber2.class.php');
//$gerber=new gerber(); //optionally pass path variables...
//$gerber->example(); //run the example app
class gerber{

	//where to extract temporary gerber files
	//relative to the script directory
	//we add the full path using __FILE__ in the script
	public $extract_path='temp_extract';

	//the location of gerbv for creating images
	//needed on windows (if not part of path)
	//not so needed on Linux
	//public 	$gerbv_path='C:\Progra~2\gerbv-2.6.1\bin\gerbv.exe';
	public 	$gerbv_path='gerbv';
	
	//where to output images created with gerbv
	//relative to the script directory
	public $image_path='order_images';
	
	//size of images to output, in pixels
	public $image_size = "500x500";	//'500x500'

	//becomes an array of error messages
	public $error=false;
	
	//these are the layers we look for and process
	public $gerber_file_desc = array(
					"gto" => "Top Overlay (Silkscreen)",
					"gts" => "Top Soldermask",
					"gtl" => "Top Layer (Copper)",
					"gbl" => "Bottom Layer (Copper)",
					"gbs" => "Bottom Soldermask",
					"gbo" => "Bottom Overlay (Silkscreen)",
					"gml" => "Board Outline (.GML)",
					"gko" => "Board Outline (.GKO)",
					"gbr" => "Board Outline (.GBR)",
					"txt" => "Drill File (.TXT)",
					"drl" => "Drill File (.DRL)",
					"dri" => "Drill File (.DRI)",
					"drd" => "Drill File (.DRD)",
					);
	//the stacking order of the gerber layers for image output
	//placing drills on top has a nice effect, etc...
	public $gerber_file_order=array('drl','txt','dri','drd','gbr','gko','gml','gto','gts','gtl','gbl','gbs','gbo');

	//hold detailed analysis of gerber files
	//populated in checkfiles(), required for createPNG()
	public $gerber_file_present; //is the file in the archive ['gto']=(true/false)
	public $gerber_filename;	//the actual filename of the each found gerber file ['gto']=myfilename.gto
	public $gerber_file_has_err; //does the layer have an error (not found, too many, etc) ['gto']=true/false
	public $gerber_file_err_msg; //any readable error message for the layer ['gto']='Multiple top overlay (silkscreen) files'
	
	//there are multiple possibilities for drill and outline files
	//this marks the type we found
	//populated in checkfiles(), required for createPNG()
	public $drill_file_ext;
	public $board_outline_ext;
	
	//optionally pass custom paths to the constructor
	//paths should be relative to the script
	function gerber($gerbv_path='', $extract_path='',$image_path=''){
		if(!empty($extract_path))$this->extract_path=$extract_path;
		if(!empty($image_path))$this->image_path=$image_path;
		if(!empty($gerbv_path))$this->gerbv_path=$gerbv_path;
	}
	
	//an example of how to use the class with gerber .zip upload form
	//usage example:
	//require_once('gerber2.class.php');
	//$gerber=new gerber(); //optionally pass path variables...
	//$gerber->example(); //run the example app
	function example(){
		//look to see if a file has been submitted, 
		//if yes, we'll process it, otherwise we'll just display the page with the form
		if(isset($_FILES["zip_file"]["name"])){
			//is zip valid (we actually check with dirtysite base class function for more security)
			//if using this on a live site never trust the MIME type
			if(!$this->checkupload()){
				echo 'Check Upload Error:';
				print_r($this->error);
			}else{
				//find out what files are in the archive, raise any errors
				//measure the outline file
				//board variable has size and stats, or is false for error
				$board=$this->checkfiles($_FILES["zip_file"]["tmp_name"]);
				$this->filereport();//give a report on board files in archive
				if(!$board){//no board outline data
					echo 'Error:';
					print_r($this->error);
				}else{
					$this->sizereport($board);//give a report on the board dimensions
					//$board=$this->gerbvSize($_FILES["zip_file"]["tmp_name"]); //second way to measure size with gerbv
					//echo 'Gerbv size measurement: ' . $board['w_cm'] . ' x ' . $board['h_cm'] . 'cm<br/>';
					$this->createPNG($_FILES["zip_file"]["tmp_name"],'1');//extract gerbers to temporary directory and create image file
					$this->imagereport('1');//give a table with images
				}
			}
		}	
		
		?>
		<html xmlns="http://www.w3.org/1999/xhtml">
		<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />

		<!-- Latest compiled and minified CSS -->
		<link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap.min.css">
		<link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap-theme.min.css">
		<script src="//netdna.bootstrapcdn.com/bootstrap/3.1.1/js/bootstrap.min.js"></script>

		<title>Gerbv Demo</title>
		</head>

		<body>
		<form enctype="multipart/form-data" method="post" action="">
		<label>Choose a zip file to upload: <input type="file" name="zip_file" /></label>
		<br />
		<input type="submit" name="submit" value="Upload" />
		</form>
		</body>
		</html>
		<?php
	}

	//pass a reference to an opened .zip object
	function gerbvSize(&$zip,$outline_filename){
		
		//extract the file to temporary extraction location in folder $name:
		//example temp_extract/$name/gerberfile.gbr
		//if there is a folder inside the .zip it will be created too:
		//example temp_extract/$name/folder_inside_zip/gerberfile.gbr
		$name='_'.basename(tempnam ( $this->extract_path . '/' , 'pcb' ),'.tmp');
		$zip->extractTo($this->extract_path . '/' . $name . '/', $outline_filename);
		
		//some (most!) people will zip up a folder and we need to get those files out
		//so lets rename the file to move it to the base directory
		//since we have to rename it anyways, let's rename each file using $name.$ext to be extra safe that no sneeky command line code is executed
		//and just to be anal, let's force the extension of the file to the detected extension
		rename( $this->extract_path . '/' . $name . '/' . $outline_filename,
				$this->extract_path . '/' . $name . '/outline.gml'); //rename to $name.ext to avoid attacks...

		//fully qualified path for gerber files and image files
		$gerber_dir = dirname(__FILE__) . '/' . $this->extract_path . '/' . $name .'/';
		$image_dir = dirname(__FILE__) . '/' . $this->image_path . '/';

		$exe=($this->gerbv_path .
				' "' . $gerber_dir . 'outline.gml" '.
				'--foreground=#000000FF --background=#FFFFFF '.
				'--export=png --dpi=254  --border=0 --log="gerbv.log" '.
				'--output="' . $gerber_dir . 'outline.png"');
		$exe=str_replace('\\','/',$exe); //make unix friendly paths
		//echo $exe;
		shell_exec($exe);

		//load image
		$im=imagecreatefrompng($this->extract_path . '/'.$name. '/outline.png');	
		$board['h_cm']=imagesx($im)/100;
		$board['w_cm']=imagesy($im)/100;
		
		//delete all files in directory
		//remove any subdirectory
		//remove temporary directory
		unlink($this->extract_path . '/'.(ltrim($name,'_')));
		$this->delTree($this->extract_path . '/'.$name);	
		
		return $board;
	
	}

	
	//pass location of .zip file containing gerbers
	//pass a $name - this is used for A) temporary extract folder, B) rename temporary gerber files, C)output image name
	//extracts to $this->extract_path
	//creates image files in $this->image_path
	//requires that $this->gerber_filename be populated by checkfiles() first!!!
	function createPNG($zipfile,$name,$color_mask='green',$surfaceCoating='HASL'){

		if(!is_file($zipfile)){
			$this->error[]='Could not find .ZIP archive.';
			return false;
		}
		
		//open zip
		$zip = new ZipArchive;
		if ($zip->open($zipfile) === false) {//open zip
			$this->error[]='Unable to open .ZIP archive.';
			return false;
		}
		
		//loop through the files in the .zip
		//only pull out the detected gerber files that we want, less opportunity to run malicious code
		if(!is_array($this->gerber_filename)){//board error, create default image set
			$this->error[]='Missing files or checkfiles() not run first!';
			return false;
		}
		
		foreach($this->gerber_filename as $ext=>&$filename){ //reference so we can change to base name (no directory) in class variable
			
			if(empty($filename))continue;//skip missing files
			
			//extract the file to temporary extraction location in folder $name:
			//example temp_extract/$name/gerberfile.gbr
			//if there is a folder inside the .zip it will be created too:
			//example temp_extract/$name/folder_inside_zip/gerberfile.gbr
			$zip->extractTo($this->extract_path . '/' . $name . '/', $filename);
			
			//some (most!) people will zip up a folder and we need to get those files out
			//so lets rename the file to move it to the base directory
			//since we have to rename it anyways, let's rename each file using $name.$ext to be extra safe that no sneeky command line code is executed
			//and just to be anal, let's force the extension of the file to the detected extension
			rename( $this->extract_path . '/' . $name . '/' . $filename,
					$this->extract_path . '/' . $name . '/' . $name . '.' . $ext); //rename to $name.ext to avoid attacks...
			
			//$filename is by reference so we can update the file name in class variable
			$filename = $name . '.' . $ext;
			
		}
		
		//base name for all images, exclude extensions like .png
		$image_base_name = $name;

		//fully qualified path for gerber files and image files
		$gerber_dir = dirname(__FILE__) . '/' . $this->extract_path . '/' . $name .'/';
		$image_dir = dirname(__FILE__) . '/' . $this->image_path . '/';
		
		//generate img of all layers
		//use gerber file order to set the order of layering for best effect
		$gerber_file_list_str='';
		foreach($this->gerber_file_order as $ext)
		{
			if($this->gerber_file_present[$ext])
				$gerber_file_list_str.= ' "' . $gerber_dir . $this->gerber_filename[$ext] . '"';
				
		}
		
		$exe=($this->gerbv_path.' ' . $gerber_file_list_str . ' --export=png --dpi=400 --border=1 --output="' . $image_dir . $image_base_name . '_all.png"');

		$exe=str_replace('\\','/',$exe); //not sure this is brilliant... replace any windows style \ with /, works on my XAMPP
		
		//echo $exe.'<br/>'; //for testing, if you want to see the string executed
		
		shell_exec($exe);

		//generate an image for each layer, include board outline
		//board outline black, layer red, background white
		foreach($this->gerber_file_desc as $ext=>$desc){
			
			if($this->gerber_file_present[$ext]){
				$exe=$this->gerbv_path;
				//add both silks to deal with oversized shit
				$exe.=($this->gerber_file_present['gto']?' "' . $gerber_dir . $this->gerber_filename['gto'] . '" ':'');
				$exe.=($this->gerber_file_present['gbo']?' "' . $gerber_dir . $this->gerber_filename['gbo'] . '" ':'');
				$exe.=	' "' . $gerber_dir . $this->gerber_filename[$this->board_outline_ext] . '" '.
						' "' . $gerber_dir . $this->gerber_filename[$ext] . '" ';
				$exe.=($this->gerber_file_present['gto']?'--foreground=#FFFFFF00 ':'');
				$exe.=($this->gerber_file_present['gbo']?'--foreground=#FFFFFF00 ':'');		
				$exe.=	'--foreground=#000000FF --foreground=#FF0000FF --background=#FFFFFF '.
						'--export=png --dpi=400 --border=1 --log="gerbv.log" '.
						'--output="' . $image_dir . $image_base_name . '_'.$ext.'.png"';
				$exe=str_replace('\\','/',$exe); //make unix friendly paths
				//echo $exe;
				shell_exec($exe);
			
			}
			
		}		

		//pcb material colors
		$colors['black']=array("red"=>0,"green"=>0,"blue"=>0,"alpha"=>255);
		$colors['white']=array("red"=>0xff,"green"=>0xff,"blue"=>0xff,"alpha"=>255);
		$colors['red']=array("red"=>192,"green"=>43,"blue"=>43,"alpha"=>255);
		$colors['yellow']=array("red"=>234,"green"=>206,"blue"=>39,"alpha"=>255);
		$colors['green']=array("red"=>68,"green"=>105,"blue"=>80,"alpha"=>255);
		$colors['blue']=array("red"=>0,"green"=>40,"blue"=>74,"alpha"=>255);
		//surface coating colors
		$colors['silver']=array("red"=>160,"green"=>160,"blue"=>160, "alpha"=>255);	
		$colors['gold']=array("red"=>227,"green"=>189,"blue"=>145, "alpha"=>255);	
		
		$color_mask=strtolower($color_mask);
		
		if($color_mask=='white') 
			$color_silk='black';
		else
			$color_silk='white';
			
		if($surfaceCoating=='ENIG')
			$color_copper='gold';
		else
			$color_copper='silver';
		
		//human readable _xxxx.png names for each layer
		$gerber_layers_images=array($this->board_outline_ext=>'outline',
									$this->drill_file_ext=>'drill',
									"gto"=>'topsilk',
									"gtl"=>'topcopper',
									"gbo"=>'bottomsilk',
									"gbl"=>'bottomcopper',);
		$gerber_layers_colors=array("outline"=>'black',
									"drill"=>'black',
									"topsilk"=>'black',
									"topcopper"=>$color_copper,
									"bottomsilk"=>'black',
									"bottomcopper"=>$color_copper,);	
		
		//modify color of each layer and save with readable name
		foreach($gerber_layers_images as $ext=>$readable_name){
			if($this->gerber_file_present[$ext]){//only process if the file exists
				$this->imageColorReplace($this->image_path . '/'.$name.'_'.$ext.'.png', $this->image_path . '/'.$name.'_'.$readable_name.'.png', $colors[$gerber_layers_colors[$readable_name]]);
			}
		
		}
							
		//top mask, takes extra work
		if($this->gerber_file_present['gts'])
			$this->imageFillMaskLayer($this->image_path . '/'.$name.'_gts.png', $this->image_path . '/'.$name.'_topmask.png',$colors[$color_mask]);			
		//bottom mask, takes extra work
		if($this->gerber_file_present['gbs'])
			$this->imageFillMaskLayer($this->image_path . '/'.$name.'_gbs.png', $this->image_path . '/'.$name.'_bottommask.png', $colors[$color_mask]);
		
	
		//top view
		$this->boardCompositImage($name, 'top',$colors[$color_silk]);
		//bottom view
		$this->boardCompositImage($name, 'bottom',$colors[$color_silk]);

		//delete all files in directory
		//remove any subdirectory
		//remove temporary directory
		$this->delTree($this->extract_path . '/'.$name);

	}	
	function getImage($file){
		if(!is_file($file)) return false;
		
		return imagecreatefrompng($file);	
	}
	function boardCompositImage($name, $side,$color_silk){

		$base = $this->getImage($this->image_path . '/'.$name.'_outline.png');
		if(!$base){ //copy could not create image image, return
			$base=$this->getImage('no_image.png');
			imagepng($base,$this->image_path . '/'.$name.'_'.$side.'.png');
			imagedestroy($base);	
			return;			
		}
		$base_y=imagesy($base);
		//apply copper layer over outline
		$im = $this->getImage($this->image_path . '/'.$name.'_'.$side.'copper.png');
		if($im){
			imagecopymerge ( $base , $im , 0 , ($base_y-imagesy($im)) , 0 , 0 , imagesx($im), imagesy($im) , 100 );		
		}

		//apply solder mask over outline
		$im= $this->getImage($this->image_path . '/'.$name.'_'.$side.'mask.png');
		if($im){
			imagecopymerge ( $base , $im , 0 , ($base_y-imagesy($im))  , 0 , 0 , imagesx($im), imagesy($im) , 100 );		
		}

		//1B copper (again) over mask faded (looks nicer)
		$im= $this->getImage($this->image_path . '/'.$name.'_'.$side.'copper.png');
		if($im){
			imagecopymerge (  $base,$im , 0 , ($base_y-imagesy($im))  , 0 , 0 , imagesx($im), imagesy($im) , 25 );
		}
		
		//2. silkscreen over
		//	correct color and add
		$im= $this->getImage($this->image_path . '/'.$name.'_'.$side.'silk.png');
		if($im){
			// Set the silk color
			$linecolor=imagecolorclosest ( $im , 0 , 0 , 0 );
			imagecolorset($im, $linecolor, $color_silk['red'], $color_silk['green'], $color_silk['blue']);	
/*
			//border
			$base_border=$base_y*.05;
			$im_border=imagesy($im)*.05;

			//image size without boarder
			$bY=$base_y-($base_border*2);//image without proportional border
			$iY=imagesy($im)-($im_border*2);
			
			//difference in raw Y
			$diff_raw=$bY-$iY;
			
			//adjust for proportional border
			$diff_border=($base_border-$im_border);
			
			//positioning
			$diff=$diff_raw+$diff_border;
			//$diff=$base_y-imagesy($im)-(($base_border-$im_border));
			
			//$diff=($base_y-($base_y*.05))-(imagesy($im)-((imagesy($im)*.05)+((imagesy($im)*.05)-($base_y*.05))));
			*/
			imagecopymerge ( $base , $im , 0 , ($base_y-imagesy($im)) , 0 , 0 , imagesx($im), imagesy($im) , 100 );
		}
		
		//3. add the drill holes to the top
		//correct color
		$im= $this->getImage($this->image_path . '/'.$name.'_drill.png');
		if($im){
			$linecolor=imagecolorclosest ( $im , 0 , 0 , 0 );
			imagecolorset($im, $linecolor, 0xff, 0xff, 0xff);	
			imagecopymerge ( $base , $im , 0 , ($base_y-imagesy($im))  , 0 , 0 , imagesx($im), imagesy($im) , 100 );		
		}

		//output final image
		imagepng($base,$this->image_path . '/'.$name.'_'.$side.'.png');
		imagedestroy($base);		
	
	
	}
	
	//fill the empty board outline
	//expects board outline to be #000000
	function imageFillMaskLayer($maskfilename, $writename, $color){
		$im=imagecreatefrompng($maskfilename);

		imagetruecolortopalette($im, false, 255);
		
		/* new way, needs to composite outline and then solder mask on top to work correctly...
		// Get the color index for the background
		$bg = imagecolorat($im, 0, 0);
		//echo $bg;
		
		//get border color. We set it to 000000 in gerbv but it comes out like this :\ fit it later I guess
		$border=imagecolorclosest ( $im ,00 , 00 , 00); //border color index
		//echo $border;
		//for($i=0;$i<imagecolorstotal($im);$i++) print_r(imagecolorsforindex($im,$i));
		
		//walk from the center top of the image down until we find a pixels of boarder color
		$X=round(imagesx($im)/2);
		$found_outline_edge=false;
		for($i=0;$i<imagesy($im);$i++){
			if(!$found_outline_edge){
				if(imagecolorat($im,$X,$i)==$border)
					$found_outline_edge=true;
			}else{//keep walking till background again		
				if(imagecolorat($im,$X,$i)==$bg){
					$Y=$i;				
					break;
				}
			}
		
		}
		
		//fill this area with PCB color
		$pcbcolor= imagecolorallocate ( $im , $color['red'], $color['green'], $color['blue'] );
		imagecolorset($im, $pcbcolor, $color['red'], $color['green'], $color['blue'], $color['alpha']);	
		imagefilltoborder($im, $X, $Y, $border, $pcbcolor);

		//mask area overlay
		//color to copper
		//overlay on top of 
		$im = imagecreatefrompng($imagefilename);
		
		//make boarder transparent
		imagecolortransparent($im, $bg);		
	*/	
		//echo 'Total colors in image: ' . imagecolorstotal($im);
		// Get the color index for the background
		$bg = imagecolorat($im, 0, 0);
		//echo $bg;
		
		//get border color. We set it to 000000 in gerbv but it comes out like this :\ fit it later I guess
		$border=imagecolorclosest ( $im ,00 , 00 , 00); //border color index
		//echo $border;
		//for($i=0;$i<imagecolorstotal($im);$i++) print_r(imagecolorsforindex($im,$i));

		//make red mask color
		$fill=imagecolorclosest ( $im , 0xff , 0 , 0 );
		//make border fill transparent
		imagecolortransparent($im, $fill);

		// Fill the area around the PCB with transparent fill
		imagefilltoborder($im, 0, 0, $border, $fill);

		// Set the remaining background to the PCB color
		imagecolorset($im, $bg, $color['red'], $color['green'], $color['blue'], $color['alpha']);

		imagepng($im,$writename);
		// Free image
		imagedestroy($im);			
	
	}
	
	
	//correct the image color, make background transparent
	//fixes: board outline is always #000000, layer content is always #FF0000
	function imageColorReplace($imagefilename, $writename, $color){
		$im = imagecreatefrompng($imagefilename);
		if(!$im)return;
		
		imagetruecolortopalette($im, false, 255);
		//echo 'Total colors in image: ' . imagecolorstotal($im);
		
		// Get the color index for the background
		$bg = imagecolorat($im, 0, 0);

		// Make the background transparent
		imagecolortransparent($im, $bg);

		// Set the front color
		//we set the good stuff on this layer to red, should be #FF0000, but it's not....
		$linecolor=imagecolorclosest ( $im , 0xff , 0 , 0 );
		imagecolorset($im, $linecolor, $color['red'], $color['green'], $color['blue'], $color['alpha']);
		
		imagepng($im,$writename);
		// Free image
		imagedestroy($im);		
	
	}
	
	//pass a .zip file location (such as the temporary location of an uploaded file)
	//optionally pass $measure to get a measurement of the board... you might not need this if only creating images and it's resource intensive
	//populates the class gerber_ analysis variables, errors go in $this->error
	//returns the $board variable with measurement info if $measure=true
	//$gerbvmeasure tries to use gerbv to measure the board, doesn't work well...
	function checkfiles($zipfile,$measure=true,$gerbvmeasure=false){
		//now let's analyze the files included in the zip folder
		$board=true;
		
		//to do things the smartest way possible, let's track things in parallel arrays
		//use class gerber file list to generate local error variable arrays
		foreach($this->gerber_file_desc as $ext=>$value){
			$gerber_file_desc[$ext]=$value;
			$gerber_file_present[$ext]=false;
			$gerber_filename[$ext]='';
			$gerber_file_has_err[$ext]=false;
			$gerber_file_err_msg[$ext]='';
		}
		
		if(!is_file($zipfile)){
			$this->error[]='Could not find .ZIP archive.';
			return false;
		}
		
		//open zip but don't extract
		//get a list of files inside
		$zip = new ZipArchive;
		if ($zip->open($zipfile) === false) {//open zip
			$this->error[]='Unable to open .ZIP archive.';
			return false;
		}
		
		//loop through files and look for extensions that match the list of gerber files in $this->gerber_file_desc
		//the list includes all files, even if within a folder
		//file name includes the full path to the file within the .zip
		//this way we don't have to care about folders nor do we have to extract the files before we know what is inside
		for($i = 0; $i < $zip->numFiles; $i++) {//loop through files
		
			$ext=strtolower(substr($zip->getNameIndex($i),-3));//get extension of file
		
			if(array_key_exists($ext,$gerber_file_desc)){	//if known file
				
				if($gerber_file_present[$ext]){ //ALREADY FOUND ONE!! OH NOES!
					$gerber_file_has_err[$ext] = true;
					$gerber_file_err_msg[$ext] = 'Multiple ".'.$ext.'" '.$gerber_file_desc[$ext].' files found in design!';
				}else{
					$gerber_file_present[$ext] = true;//found file
					$gerber_filename[$ext] = $zip->getNameIndex($i);//store name and location in .zip archive					
				}				

			}								   

		}		
		
		//check which board outline type is used, if any...
		//$board_outlines=array('gml','gko','gbr');
		$board_outline_ext=false;
		foreach($gerber_file_desc as $ext=>$desc){
			
			if(substr($desc,0,13)!='Board Outline') continue;
			
			if($gerber_file_present[$ext]){
				$board_outline_ext = $ext;
				break;
			}
		
		}
		//no outline error
		if(!$board_outline_ext){
			$this->error[]='No board outline (.GML/.GKO/.GBR) file found.';
			return false;
		}

		//check which drill type is used, if any...
		//$drill_files=array('drl','txt');
		$drill_file_ext=false;
		foreach($gerber_file_desc as $ext=>$desc){
			
			if(substr($desc,0,5)!='Drill') continue;
			
			if($gerber_file_present[$ext]){
				$drill_file_ext = $ext;
				break;
			}
				
		}
		//no drill error
		if(!$drill_file_ext){
			$this->error[]='No drill (.DRL/.TXT) file found.';
			return false;
		}
		
		if($gerbvmeasure){
			$board=$this->gerbvSize($zip,$gerber_filename[$board_outline_ext]); //second way to measure size with gerbv
		}
		
		if($measure){
		
			//now get the board outline file and run the size check
			//create a file handle directly to the outline within the zip, read from here
			//no need to extract anything
			$fh=$zip->getStream($gerber_filename[$board_outline_ext]);
			if (!$fh) {		//can we open it
				$this->error[]='Corrupt .zip file, could not read board outline file.';
				return false;
			}
			
			//one problem with zipStream is that we can't get the full file size like we can with the zip_ functions
			//(at least as far as I can tell)
			//need to figure this out, or just count bytes and set
			/*//too big, attack?
			if(zip_entry_filesize($zip_entry)>1000000){//too big, attack?
				$this->error[]='Board outline (GML/GKO/GBR) file too big!';
				return false;
			}*/
			
			//calculate board size
			$board=$this->measurepcb($fh);
					
			if(!$board){
				$this->error[]="Board outline not found in GML/GBR/GKO file.";
				return false;
			}
		}

		//close .zip archive
		$zip->close();
		
		//stuff local variables into class variables
		//sorry, this is pretty dodgy...
		$this->gerber_file_present=$gerber_file_present;
		$this->gerber_filename=$gerber_filename;
		$this->gerber_file_has_err=$gerber_file_has_err;
		$this->gerber_file_err_msg=$gerber_file_err_msg;
		
		$this->board_outline_ext=$board_outline_ext;
		$this->drill_file_ext=$drill_file_ext;
			
		return $board;

	}

	//takes a file handle to a board outline file (GKO/GML/GBR) and looks for the dimensions
	//returns $board variable with dimension and other info
	//if no outline returns false, check $this->error for an array of errors raised
	function measurepcb(&$fh){

		$board['number_format']='unknown';
		$board['coordinate_mode']='unknown';
		$board['units']='unknown';
		$numformat='L';//give a default...
		$coordmode='A';//give a default
		
		$units = "";
		$x_digs_before_decimal;
		$x_digs_after_decimal;
		$y_digs_before_decimal;
		$y_digs_after_decimal;
					
		$min_x_pt = 999999999;
		$min_y_pt = 999999999;
		$max_x_pt = -999999999;
		$max_y_pt = -999999999;

		while (!feof($fh)) 
		{ 
			$line = stream_get_line($fh, 1000000, "\n"); 
			
			//look for number formating
			if(substr($line,0,3) == '%FS')
			{		
				$numformat = substr($line,3,1) ;
				if($numformat == 'L')
					$board['number_format']= "Leading zeros omitted";
				elseif($numformat == 'T')
					$board['number_format']= "Trailing zeros omitted";
				elseif($numformat == 'D')
					$board['number_format']= "Explicit decimal point";
				else
					$board['number_format']= "unknown";
				
				$coordmode = substr($line,4,1);
				if($coordmode == 'A')
					$board['coordinate_mode']="absolute";
				elseif($coordmode == 'I')
					$board['coordinate_mode']="incremental";
				else
					$board['coordinate_mode']="unknown";
				
				  $re1='.*?';	# Non-greedy match on filler
				  $re2='(X)';	# Any Single Character 1
				  $re3='(\\d)';	# Any Single Digit 1
				  $re4='(\\d)';	# Any Single Digit 2
				  $re5='(Y)';	# Any Single Character 2
				  $re6='(\\d)';	# Any Single Digit 3
				  $re7='(\\d)';	# Any Single Digit 4

				  if (preg_match("/".$re1.$re2.$re3.$re4.$re5.$re6.$re7."/is", $line, $matches))
				  {
					$x_digs_before_decimal = $matches[2];
					$x_digs_after_decimal = $matches[3];
					$y_digs_before_decimal = $matches[5];
					$y_digs_after_decimal = $matches[6];
				  }
			}
			elseif(substr($line,0,3) == 'G70')	//check for the Gcode for inches
			{
				$units = "in";
			}
			elseif(substr($line,0,3) == 'G71')	//check for the Gcode for mm
			{
				$units = "mm";
			}
			elseif(substr($line,0,7) == '%MOIN*%') //looking for units called out in the header, inches
			{
				$units = "in";
			}
			elseif(substr($line,0,7) == '%*MOMM*%') //looking for units called out in the header, mm
			{
				$units = "mm";
			}
			
			
			if(substr($line,0,1) != '%')
			{
				//it's not part of the header, track the coordinates for min and maximums
				if(preg_match("/(X)([-]?\\d+)(Y)([-]?\\d+)((?:[a-z][a-z]*[0-9]+[a-z0-9]*))/is",$line,$matches))
				{
					
					if($numformat == 'T')	//This adjusts for the case of Trailing zeros omitted
					{
						$matches[2] = $matches[2] * pow(10,( ($x_digs_before_decimal + $x_digs_after_decimal) - strlen($matches[2])));
						$matches[4] = $matches[4] * pow(10 ,( ($y_digs_before_decimal + $y_digs_after_decimal) - strlen($matches[4])));
					}
					
					//this case catches lines with both x and y values
					if($matches[2] < $min_x_pt)
						$min_x_pt = $matches[2];
					elseif($matches[2] > $max_x_pt)
						$max_x_pt = $matches[2];
						
					if($matches[4] < $min_y_pt)
						$min_y_pt = $matches[4];
					elseif($matches[4] > $max_y_pt)
						$max_y_pt = $matches[4];
				}
				elseif(preg_match("/(X)([-]?\\d+)/is",$line,$matches))
				{
					if($numformat == 'T')	//This adjusts for the case of Trailing zeros omitted
					{
						$matches[2] = $matches[2] * pow(10, ( ($x_digs_before_decimal + $x_digs_after_decimal) - strlen($matches[2])));
					}
					
					//this case catches lines with only x coords (y is unchanged)
					if($matches[2] < $min_x_pt)
						$min_x_pt = $matches[2];
					elseif($matches[2] > $max_x_pt)
						$max_x_pt = $matches[2];
				}
				elseif(preg_match("/(Y)([-]?\\d+)/is",$line,$matches))
				{
					//this case catches lines with only y coords (x is unchanged)
					
					if($numformat == 'T')	//This adjusts for the case of Trailing zeros omitted
					{
						$matches[2] = $matches[2] * pow(10 , ( ($y_digs_before_decimal + $y_digs_after_decimal) - strlen($matches[2])));
					}
					
					if($matches[2] < $min_y_pt)
						$min_y_pt = $matches[2];
					elseif($matches[2] > $max_y_pt)
						$max_y_pt = $matches[2];
				}
				
			}
		}//end of file 
		
		if( $min_x_pt == 999999999 && $min_y_pt == 999999999)
		{
			return false;
		}
		elseif($units=='unknown')
		{
			$this->error[]='GML/GKO/GBR file missing units.';
			return false;
		}
		else
		{
			$board['x_min']=number_format(($min_x_pt / pow(10,$x_digs_after_decimal)),$x_digs_after_decimal);
			$board['x_max']=number_format(($max_x_pt / pow(10,$x_digs_after_decimal)),$x_digs_after_decimal);
			$board['y_min']=number_format($min_y_pt / pow(10,$y_digs_after_decimal),$y_digs_after_decimal);
			$board['y_max']=number_format($max_y_pt / pow(10,$y_digs_after_decimal),$y_digs_after_decimal);
			$board['w_raw']=number_format(($max_x_pt - $min_x_pt)/pow(10,$x_digs_after_decimal),2);
			$board['h_raw']=number_format(($max_y_pt - $min_y_pt)/pow(10,$y_digs_after_decimal),2);
			$board['units']=$units;	

			if($units == "in")//convert inches to cm
			{
				$board['w_cm']=number_format((($max_x_pt - $min_x_pt)/pow(10,$x_digs_after_decimal)*2.54),2);
				$board['h_cm']=number_format((($max_y_pt - $min_y_pt)/pow(10,$y_digs_after_decimal))*2.54,2);
			}
			else //convert mm to cm...
			{
				$board['w_cm']=number_format((($max_x_pt - $min_x_pt)/pow(10,$x_digs_after_decimal+1)),2);
				$board['h_cm']=number_format((($max_y_pt - $min_y_pt)/pow(10,$y_digs_after_decimal+1)),2);
			}
		}	

		return $board;
		
	}

	//------------------------------------------------------//
	//			HELPER FUNCTIONS							//
	//------------------------------------------------------//
	
	//check if the measured board will fit in a certain size PCB
	function sizeCheck($board, $maxsize){
		//made width the widest (or the same...)
		if($maxsize[0]>$maxsize[1]){
			$orderWidth=$maxsize[0];
			$orderHeight=$maxsize[1];
		}else{
			$orderWidth=$maxsize[1];
			$orderHeight=$maxsize[0];
		}	

		//find longest edge of board, make width
		if($board['w_cm']>$board['h_cm']){
			$pcbWidth=$board['w_cm'];
			$pcbHeight=$board['h_cm'];
		}else{
			$pcbWidth=$board['h_cm'];
			$pcbHeight=$board['w_cm'];								
		}

		if(($orderWidth<$pcbWidth) || ($orderHeight<$pcbHeight)){
			$this->error[]=$pcbWidth.'x'.$pcbHeight.'cm too big for '.$orderWidth.'x'.$orderHeight.'cm PCBs';
			return false;
		}	
		return true;
	}
	
	//check for validity of .zip files
	function checkupload(){
		$filename = $_FILES["zip_file"]["name"];
		$source = $_FILES["zip_file"]["tmp_name"];
		$type = $_FILES["zip_file"]["type"];
		
		$name = explode(".", $filename);
		$accepted_types = array('application/zip', 'application/x-zip-compressed', 'multipart/x-zip', 'application/x-compressed');
		
		//make sure it's a valid .zip folder
		//put can't trust mime, be sure to do this another way too
		if(!in_array($type,$accepted_types)){
			$this->error[]='Unknown MIME type.';
			return false;
		}
		
		//see if the extension is '.zip'
		if(strtolower(substr($filename,-4))!='.zip'){
			$this->error[]= 'Not a .ZIP file archive.';
			return false;
		}
		
		return true;
	}
	
	function delTree($dir) {
	   $files = array_diff(scandir($dir), array('.','..'));
		foreach ($files as $file) {
		  (is_dir("$dir/$file") && !is_link($dir)) ? rmdir("$dir/$file") : unlink("$dir/$file");
		}
		return rmdir($dir);
	  } 

	//------------------------------------------------------//
	//				TEST FUNCTIONS							//
	//------------------------------------------------------//	
	
	//display a tables of the files in the gerber archive and any errors
	function filereport(){
		//now all the files have been processed and we know what we're looking at.
		//lets display the results in a table
		echo "<h2>Archive Analysis:</h2>";
		echo "<table>";
		
		foreach ($this->gerber_file_desc as $key => $item)
		{
			echo "<tr>";
			
			echo "<td>" . $item . "</td>";
			
			echo "<td>";
				if($this->gerber_file_has_err[$key])
					echo $this->gerber_file_err_msg[$key];
				elseif($this->gerber_file_present[$key])
					echo $this->gerber_filename[$key];
				else
					echo "[Not Found]";
			echo "</td>";
			
			echo "</tr>";
		}
		
		echo "</table><br><br>";	
	
	}
	
	//display a table of the board size measurements
	function sizereport($board){
		echo "Detected number format: ".$board['number_format'].'<br/>';
		echo "Detected coordinate mode: ".$board['coordinate_mode'].'<br/>';
		echo "Units: " . $board['units'] . "<br>"; 			
		echo "X-dir:<br>";
		echo "min: " . $board['x_min'] ."<br>";
		echo "max: " . $board['x_max'] ."<br>";
		echo "<br>";
		echo "Y-dir:<br>";
		echo "min: " . $board['y_min'] ."<br>";
		echo "max: " . $board['y_max'] ."<br>";
		echo "<br>";
		echo "PCB Size: " . $board['h_raw'] . " x " . $board['w_raw'] . $board['units'].'<br/>';
		echo "PCB Size: " . $board['h_cm'] . " x " . $board['w_cm'] . "cm<br/>";
	}	
	
	//display a table of the board images
	function imagereport($img_name){
		echo "<h2>PCB Views</h2>";
		echo "<table>";
		echo "<tr><td rowspan=2</td>All Layers<br><img src='".$this->image_path."/" . $img_name . "_all.png'><td>Top View<br><img src='".$this->image_path."/" . $img_name . "_top.png'></td></tr>";
		echo "<tr><td>Bottom View<br><img src='".$this->image_path."/" . $img_name . "_bottom.png'></td></tr>";
		echo "</table>";	
	}
	
}

?>