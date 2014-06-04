<?php
class Encoding extends Eloquent {

	public static $timestamps = true;


	/**
	 * Encoding::addToQueue()
	 * Adds project with arguments to the processing queue and then tells the queue to proceed.
	 *
	 * @param string $project
	 * @param mixed $arguments
	 * @return void
	 */
	public static function addToQueue($project = 'Scene', $arguments = null, $encode_id = ''){
		if( !is_object($arguments) && is_array($arguments) ){
			$arguments = (object)$arguments;
		}

		// If there's an encode id in the arguments but no in the params passed, set it
		if( empty($encode_id) && !empty($arguments->encode_id) ){
			$encode_id = $arguments->encode_id;
		}

		// If there is still no encode id, we need to set one
		if( empty($encode_id) ){
			$encode_id = md5(date('Y-m-d H:i:s') . rand(0, 1000000));
		}

		// Scheduling
		$scheduled = ( !empty($arguments->scheduled) ) ? $arguments->scheduled : null;

		self::add($encode_id, array('project' => $project, 'status' => 'queue', 'currently' => 'Queued', 'scheduled' => $scheduled), $arguments);
		self::processQueue($project);
	}

	/**
	 * Encoding::add()
	 * Adds (or updates if already existing) an entry in the encoding db table.
	 *
	 * @param string $encode_id
	 * @param mixed $arguments - specific db fields, project, code, status, etc
	 * @param mixed $params - params to accompany encoding project, such as site_id, scene_id, dvd_id, etc
	 * @return integer - insert id if insert, # affected rows on update
	 */
	public static function add($encode_id = '', $arguments = null, $params = null){
		// Put params into json format
		if( !empty($params) ){
			if( !is_array($params) ){ $params = (array)$params; }
			$params = json_encode($params);
		}
		else {
			$params = '';
		}

		// Set details from arguments passed
		$project = !empty($arguments['project'] ) ? $arguments['project'] : null;
		$status = !empty($arguments['status'] ) ? $arguments['status'] : null;
		$code = !empty($arguments['code'] ) ? $arguments['code'] : 0;
		$currently = !empty($arguments['currently']) ? $arguments['currently'] : null;

		// Default for the database, empty. 
		$data = array();
		if( !empty($arguments['scheduled']) ){ $data['scheduled'] = $arguments['scheduled']; }

		// Check for existing encode id to see if this deserves an update
		$existing = self::where('encode_id', '=', $encode_id)->first();

		// If data already exists, update
		if( !empty($existing) ){

			// Update details. Only update what is not blank.
			if( !empty($project) ){ $data['project'] = $project; }
			if( !empty($status) ){ $data['status'] = $status; }
			if( !empty($currently) ){ $data['currently'] = $currently; }
			if( !empty($params) ){ $data['params'] = $params; }
			$data['code'] = $code;
			$data['updated_at'] = date('Y-m-d H:i:s');

			// Do update in db
			return self::where('encode_id', '=', $encode_id)->update($data);
		}

		// If we've made it this far, that means there is no record so insert
		$data['encode_id'] = $encode_id;
		$data['project'] = $project;
		$data['currently'] = $currently;
		$data['params'] = $params;
		$data['code'] = $code;
		$data['status'] = $status;
		$data['created_at'] = date('Y-m-d H:i:s');
		$data['updated_at'] = date('Y-m-d H:i:s');

		// Do insert in db
		return self::insert_get_id($data);
	}


	/**
	 * Encoding::update()
	 * Updates the 'currently' field by encode_id
	 *
	 * @param string $encode_id
	 * @param string $currently
	 * @return integer # affected rows
	 */
	public static function update($encode_id = '', $currently = ''){
		$data = array(
			'currently' => $currently
		);

		return self::where('encode_id', '=', $encode_id)->update($data);
	}


	/**
	 * Encoding::check_queue()
	 * Checks queue either by project type or by any type, returns true if queue exists. False if not.
	 *
	 * @param string $project
	 * @return boolean
	 */
	public static function check_queue($project = ''){
		// if project is not blank, check for existing queued items for that project
		if( !empty($project) ){
			// Only need one. If there is a queue (a record exists), return true.
			$queue = self::where('project', '=', $project)->where(function($query){
				$query->where('status', '=', 'queue');
				$query->or_where('status', '=', 'running');
			})
				->first();

			if( !empty($queue) ){
				return true;
			}
			else {
				return false;
			}
		}
		// If project is blank, check for a queue regardless of project type
		else {
			$queue = self::where('status', '=', 'queue')->or_where('status', '=', 'running')->first();
			if( !empty($queue) ){
				return true;
			}
			else {
				return false;
			}
		}
	}


	/**
	 * Encoding::complete()
	 * Sets an encode process to complete
	 *
	 * @param string $encode_id
	 * @return integer # affected rows
	 */
	public static function complete($encode_id = ''){
		$data = array(
			'currently' => 'complete',
			'completed_at' => date('Y-m-d H:i:s'),
			'status' => 'complete'
		);

		return self::where('encode_id', '=', $encode_id)->update($data);
	}


	/**
	 * Encoding::getEncoding()
	 * Returns a single row from the database based on $encode_id
	 *
	 * @param string $encode_id
	 * @return mixed
	 */
	public static function getEncoding($encode_id = ''){
		return self::where('encoding_id', '=', $encode_id)->first();
	}


	/**
	 * Encoding::getNotComplete()
	 * Return any rows in the database showing as not complete
	 *
	 * @return
	 */
	public static function getNotComplete(){
		return self::where('status', '=', 'queued')->or_where('status', '=', 'running')->get();
	}

	/**
	 * Encoding::getProgress()
	 * Fetches the duration and it's current point in the encoding to return a % value from the encoding txt file of the same name as the encode_id
	 *
	 * @param string $encode_id
	 * @return integer
	 */
	public static function getProgress($encode_id = ''){
		$encode_file = path('storage') . 'logs' . DS . 'ffmpeg' . DS . $encode_id . '.txt';

		// If the file doesn't even exist, stop now.
		if( !file_exists($encode_file) ){
			return 0;
		}

		// Read the file's contents
		$file = fopen($encode_file, 'r');
		$content = fread($file, filesize($encode_file));
		fclose($file);

		// If permission denied is found, just end now.
		preg_match("/(Permission denied)/", $content, $matches);
		if( !empty($matches) ){
			return 'Permission Denied';
		}

		// # get duration of source
		preg_match("/Duration: (.*?), start:/", $content, $matches);
		if( empty($matches[1]) ){
			return;
		}

		$rawDuration = $matches[1];

		// # rawDuration is in 00:00:00.00 format. This converts it to seconds.
		$ar = array_reverse(explode(":", $rawDuration));
		$duration = floatval($ar[0]);
		if (!empty($ar[1])) $duration += intval($ar[1]) * 60;
		if (!empty($ar[2])) $duration += intval($ar[2]) * 60 * 60;

		// # get the current time
		preg_match_all("/time=(.*?) bitrate/", $content, $matches);

		$last = array_pop($matches);

		// # this is needed if there is more than one match
		if (is_array($last)) {
			$last = array_pop($last);
		}

		$ar = array_reverse(explode(":", $last));
		$curTime = floatval($ar[0]);
		if (!empty($ar[1])) $curTime += intval($ar[1]) * 60;
		if (!empty($ar[2])) $curTime += intval($ar[2]) * 60 * 60;

		//$curTime = floatval($last);

		// # finally, progress is easy
		$progress = round(($curTime/$duration) * 100);

		return $progress;
	}



	/**
	 * Encoding::scene()
	 * Process scene - Runs the ffmpeg encoding process on a dvd's scene.
	 *
	 * @param mixed $arguments
	 * @return void
	 */
	public static function scene($task_id = 0, $arguments = null){
		set_time_limit(0);

		// Begin
        Tasks::updateTask($task_id, null, Tasks::STATUS_IN_PROGRESS, 'Started');

		// Ensure that we are working with an object
		if( !is_object($arguments) && is_array($arguments) ){
            $arguments = (object)$arguments;
		}

		// Pull params and then convert to an array for passing to other methods
		$scene_id = !empty($arguments->scene_id) ? $arguments->scene_id : null;
		$dvd_id = !empty($arguments->dvd_id) ? $arguments->dvd_id : null;
		$site_id = !empty($arguments->site_id) ? $arguments->site_id : null;
		$encode_id = !empty($arguments->encode_id) ? $arguments->encode_id : md5(time() . rand(0, 10000000));
		$file_location = !empty($arguments->file_location) ? $arguments->file_location : null;
		// Convert to array so that it can be passed to the encode::add method for data entry.
		$arguments = (array)$arguments;

		// We really should do this for all vars but the file location is the one most likely to be missed
		if( empty($file_location) ){
            return false;
		}

        // retrieve dvd and scene listings for filename purposes later
        $dvd = Dvd::where('dvd_id', '=', $dvd_id)->where('site_id', '=', $site_id)->first();
        $scene = Scene::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->first();

        // Get video details for later
        $videoDetails = ffmpeg::getDetails($file_location);
        echo 'File: ' . $file_location . "\n\r";
        echo 'video details: ';
        if( empty($videoDetails['srcWidth']) ){ $videoDetails['srcWidth'] = 1920; }
        if( empty($videoDetails['srcHeight']) ){ $videoDetails['srcHeight'] = 1080; }
        print_r($videoDetails);

		/*** STORE ORIGINAL ***/
		// Set filename
        $source_pathinfo = pathinfo($file_location);
        $ext = !empty($source_pathinfo['extension']) ? $source_pathinfo['extension'] : null;
        $basename = basename($file_location, $ext);
        echo 'Basename: ' .$basename. "\n";
		$filename = basename($file_location);

        // Path to where the original should be placed.
        $storagePathOriginal = Config::get('ztod.path_originals') . $site_id . DS . 2 . DS . $dvd_id . DS;

        // If the storage path and the path used to do the encode are one and the same, then nothing
        // more needs to be done with this as the original is obviously already stored.
        if( $file_location == $storagePathOriginal . $source_pathinfo['filename'] ){
            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Using stored original. No need to add to storage.');
        }
        else {
            // First job is to take the original and store it and copy it over.
            $volume = 2;
            $checkOriginal = Files_Originals::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->first();

            // Insert if not already in the database
            if( empty($checkOriginal) ){
                $data = array(
                    'scene_id' => $scene_id,
                    'site_id' => $site_id,
                    'format_id' => 14,
                    'dvd_id' => $dvd_id,
                    'filename' => $filename,
                    'volume' => $volume,
                    'size' => filesize($file_location),
                    'encode_status' => 'pending',
                    'encode_id' => $encode_id
                );
                Files_Originals::insert($data);

                Tasks::updateTask($task_id, null, 'In Progress', 'Adding original file to the database');
                //self::add($encode_id, array('project' => $project, 'status' => 'running', 'currently' => 'Adding original file to the database'), $arguments);
            }
            // Update record just in case file is different
            else {
                $data = array(
                    'format_id' => 14,
                    'dvd_id' => $dvd_id,
                    'filename' => $filename,
                    'volume' => $volume,
                    'size' => filesize($file_location),
                    'encode_status' => 'pending',
                    'encode_id' => $encode_id
                );
                Files_Originals::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->update($data);
                Tasks::updateTask($task_id, null, 'In Progress', 'Updated original file in database');
                //self::add($encode_id, array('project' => $project, 'status' => 'running', 'currently' => 'Updated original file in database'), $arguments);
            }

            // New location for the file (the uploaded file will be deleted at the end of this process)
            $path = Config::get('ztod.path_originals') . $site_id . DS . $volume . DS . $dvd_id . DS;
            if( !is_dir($path) ){
                if( Helper::mkdir($path) == false ){
                    Tasks::updateTask($task_id, null, 'Failed', 'Unable to create folder: ' . $path);
                    //Encoding::add($encode_id, array('project' => $project, 'status' => 'fail', 'currently' => 'Unable to create folder: ' . $path), $arguments);
                    echo 'Unable to create ' . $path;
                    exit;
                }
            }

            // Begin recording the process here
            Tasks::updateTask($task_id, null, Tasks::STATUS_IN_PROGRESS, 'Copying original to storage');
            //Encoding::add($encode_id, array('project' => $project, 'currently' =>'Copying original to storage', 'status' => 'running'), $arguments, 0);

            // Copy file
            shell_exec('cp \'' . $file_location . '\' \'' . $path . '\'  >/dev/null 2>/dev/null &');
        }
		/*** END STORE ORIGINAL ***/

        // Generate tooltips
        $ss_dest = Config::get('ztod.path_screenshots') . $site_id . DS . $dvd_id . DS . $scene_id . DS . 'tooltip' . DS;
        ffmpeg::generateTooltips($file_location, $ss_dest);

        // Video sizes to encode - Retrieve from config/ztod.php
		$vid_sizes = Config::get('ztod.ffmpeg');

        // Build source and destination paths
		$source = $file_location;
		$workPath = path('storage').'work' . DS . $encode_id . DS;
		if( !is_dir($workPath) ){
			if( Helper::mkdir($workPath) == false ){
                Tasks::updateTask($task_id, null, Tasks::STATUS_FAILED, 'Unable to create destination: ' . $workPath);
				exit;
			}
		}

		// Set watermark
        $watermark = self::watermark($site_id, 'scene', $videoDetails['srcWidth'], $videoDetails['srcHeight']);
        echo 'Watermark: ' . $watermark . "\n";

        // MPG options, to be moved to the db at some point
        $encoding_options = array(
            'mpg' => array(
                'size' => $videoDetails['srcWidth'] . 'x' . $videoDetails['srcHeight'],
                'ext' => 'mpg',
                'params' => array(
                    'vcodec' => 'mpeg1video',
                    'preset' => 'medium',
                    'pix_fmt' => 'yuv420p',
                    'b' => '10000k'
                )
            )
        );

        // Filename for the tmp mpg
        $filename = $dvd->slug . '-scene' . $scene->id_place . '-tmp.mpg';

        // Create an mpg from the original
        Tasks::updateTask($task_id, 0, tasks::STATUS_IN_PROGRESS, 'Encoding MPG in prep for intro/outro');

        ffmpeg::setEncodeId($encode_id);
        ffmpeg::source($source);
        ffmpeg::destination($workPath . $filename);
        if( !empty($watermark) ){ ffmpeg::set_watermark($watermark); }
        ffmpeg::set_vids($encoding_options);
        ffmpeg::process($encode_id);

        // new source
        $source = $workPath . $filename;

        // GET OR PROCESS INTROS
        // retrieve or create intro/outro
        $intros = self::intros($site_id, $videoDetails['srcWidth'], $videoDetails['srcHeight']);

        // If there are intros and/or outros, we need added conversions.
        if( !empty($intros) ){
            // file list including new mpg file.
            $filelist = array(
                $intros['in'],
                $source,
                $intros['out']
            );

            // Concat intro, mpg and outro into one.
            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Concat intro, mpg file and outro together');
            ffmpeg::concat($filelist, $workPath . DS . 'concat.mpg', '', $encode_id);

            // Remove the old tmp file, keep things clean
            @unlink($workPath . $filename);

            // Filename for the new mpg
            $filename = $dvd->slug . '-scene' . $scene->id_place . '.mpg';

            // Need to rename concat.mpg back for the proper file naming structure of the next encode
            rename($workPath . DS . 'concat.mpg', $workPath . $filename);

            // reset the source to the new concat
            $source = $workPath . $filename;
        }

        // Need a new filename, one that will include %d so that the format can be used
        $filename = $dvd->slug . '-scene' . $scene->id_place . '%d.mpg';
        $workPath = $workPath . $filename;

		// Set ID to ffmpeg
		ffmpeg::setEncodeId($encode_id);

		// Set paths to ffmpeg
		if( ffmpeg::source($source) == false ){
			echo ffmpeg::error();
            Tasks::updateTask($task_id, null, Tasks::STATUS_FAILED, 'Unable to set source ' . ffmpeg::error());
			//Encoding::add($encode_id, array('status' => 'fail', 'currently' => 'Unable to set source ' . ffmpeg::error()));
			exit;
		}

		if( ffmpeg::destination($workPath) == false ){
			echo ffmpeg::error();
            Tasks::updateTask($task_id, null, Tasks::STATUS_FAILED, 'Unable to set destination: ' . ffmpeg::error());
			//Encoding::add($encode_id, array('status' => 'fail', 'currently' => 'Unable to set destination ' . ffmpeg::error()));
			exit;
		}

		// Creates the galleries laid out in /config/ztod.php, which are 'gallery' and 'tooltip'
        Tasks::updateTask($task_id, null, Tasks::STATUS_IN_PROGRESS, 'Generating Image Galleries');
		Scene::generateGallery($scene_id, $site_id, $dvd_id);

		// Set vids and watermarks to ffmpeg
		ffmpeg::set_vids($vid_sizes);
        // If intros were added, the watermark should already be done.
        // Otherwise we need to add watermarks now
        if( empty($intros) ){
            ffmpeg::set_watermark($watermark);
        }

		// Start processing
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Encoding video files: ' . $dvd_id . '-' . $scene_id);

        //Encoding::add($encode_id, array('currently' => 'Encoding video files: ' . $dvd_id . '-' . $scene_id), $arguments, 1);
		ffmpeg::process($encode_id);

		// Moves the files from the work folder (by $id) to the storage volumes and adds to database
		Scene::moveFiles($task_id, (object)$arguments);

		// Finish up!
        Tasks::updateTask($task_id, 100, 'Completed', 'Complete', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'));
		//Encoding::complete($encode_id);

        // Update scene
        Scene::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->update(array(
            'tooltip_thumbs' => 1
        ));

        // Clean up by removing the work folder.
        Helper::rmdir($workPath);
		return true;
	}



	/**
	 * Encoding::trailer()
	 * Encode a trailer based on params sent in arguments array
	 *
	 * @param mixed $arguments
	 * @return
	 */
	public static function trailer($task_id = 0, $arguments = null){
		// defaults
		$project = 'Trailer';
		$format_id = 2;

		// Ensure that we are working with an object
		if( !is_object($arguments) && is_array($arguments) ){
            $arguments = (object)$arguments;
		}

		// Pull params and then convert to an array for passing to other methods
		$scene_id = !empty($arguments->scene_id) ? $arguments->scene_id : null;
		$dvd_id = !empty($arguments->dvd_id) ? $arguments->dvd_id : null;
		$site_id = !empty($arguments->site_id) ? $arguments->site_id : null;
		$encode_id = !empty($arguments->encode_id) ? $arguments->encode_id : md5(time() . rand(0, 10000000));
		$file_location = !empty($arguments->file_location) ? $arguments->file_location : null;
		// Convert to array so that it can be passed to the encode::add method for data entry.
		$arguments = (array)$arguments;

		// no file passed? Die.
		if( empty($file_location) ){
            Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'No file specified');
			//self::add($encode_id, array('project' => $project, 'status' => 'fail', 'currently' => 'No file specified'), $arguments);
			return false;
		}

		if( empty($scene_id) ){
            Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'No scene specified');
			//self::add($encode_id, array('project' => $project, 'status' => 'fail', 'currently' => 'No scene specified'), $arguments);
			return false;
		}

		// Get the dvd and scene info
		$dvd = Dvd::where('dvd_id', '=', $dvd_id)->where('site_id', '=', $site_id)->first();
		$scene = Scene::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->first();

		// Put together new file name
		$destFilename = $dvd->slug . '-trailer-scene' . $scene->id_place . '-' . $format_id . '.mp4';
		$destOriginalFilename = $dvd->slug . '-trailer-scene' . $scene->id_place . '-' . $format_id . '.mov';

		// Copy original to the trailers_originals folder
		$path_trailers_originals = Config::get('ztod.path_originals_trailers');
		$volumes = glob($path_trailers_originals . $site_id . DS . '[0-9]', GLOB_ONLYDIR); // returns only the folders that are digits
		$volume = end($volumes); // Want the highest #
		$volume = basename($volume); // To only have final folder name

        // Generate tooltips
        $tooltipDestinationPath = Config::get('ztod.path_screenshots') . $site_id . DS . $dvd_id . DS . $scene_id . DS . 'tooltip_trailer' . DS;
        if( is_dir($tooltipDestinationPath) ){ Helper::rmdir($tooltipDestinationPath); }
        Helper::mkdir($tooltipDestinationPath);
        ffmpeg::generateTooltips($file_location, $tooltipDestinationPath);

        // Piece together into a final destination
		$destination = $path_trailers_originals . $site_id . DS . '1' . DS . $dvd_id;

        if( !file_exists($file_location) ){
            Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'File not found: ' . $file_location);
            return;
        }

        if( $destination . DS . basename($file_location) == $destination . DS . $destOriginalFilename ){
            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Using file from /storage/originals');
        }
        else {
            // If not already created, create it.
            if( !is_dir($destination) ){
                Helper::mkdir($destination);
            }

            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Copying original to storage');

            // Copy file via shell command and then rename it.
            shell_exec('cp \'' . $file_location . '\' \'' . $destination . '\'');
            rename($destination . DS . basename($file_location), $destination . DS . $destOriginalFilename);

            // First remove the old
            DB::table('trailers_originals')->where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->delete();

            // Insert into db
            DB::table('trailers_originals')->insert(array(
                'scene_id' => $scene_id,
                'site_id' => $site_id,
                'type' => 'scene',
                'format_id' => 17,
                'dvd_id' => $dvd_id,
                'filename' => $destOriginalFilename,
                'volume' => $volume,
                'size' => filesize($destination . DS . $destOriginalFilename),
                'encode_status' => 'done',
                'date_started' => date('Y-m-d H:i:s'),
                'date_encoded' => date('Y-m-d H:i;s')
            ));
        }

        // retrieve the video details
        $videoDetails = ffmpeg::getDetails($file_location);
        if( empty($videoDetails['srcWidth']) ){ $videoDetails['srcWidth'] = 1920; }
        if( empty($videoDetails['srcHeight']) ){ $videoDetails['srcHeight'] = 1080; }
        echo 'Video Details: ';
        print_r($videoDetails);

		// FFMPEG params for trailers
		$vid_sizes = Config::get('ztod.ffmpeg_trailer');

		// Set watermark
        $watermark = self::watermark($site_id, 'scene', $videoDetails['srcWidth'], $videoDetails['srcHeight']);

        // Work folder destination
        $workPath = path('storage') . 'work' . DS . $encode_id . DS;

        // tmp work name for the mpg video
        $filename = $dvd->slug . '-trailer-scene' . $scene->id_place . '-' . $format_id . '.mpg';

        // Encode trailer into mpg format with watermark if available.
        ffmpeg::setEncodeId($encode_id);
        ffmpeg::source($file_location);
        ffmpeg::destination($workPath . $filename);
        if( !empty($watermark) ){ ffmpeg::set_watermark($watermark); }
        ffmpeg::set_vids(Config::get('ztod.ffmpeg_trailer_mpg'));
        ffmpeg::process($encode_id);

        // New source to use for future encodes
        $source = $workPath . $filename;


        // Retrieve or create intros for this trailer based on site id and widthxheight
        $intros = self::intros($site_id, 1280, 720);

        // If there are intros
        if( !empty($intros) ){
            $fileList = array(
                $intros['in'],
                $workPath . $filename,
                $intros['out']
            );
            ffmpeg::concat($fileList, $workPath . 'concat.mpg', '', $encode_id);

            // remove work mpg and create new mpg with intros and outros
            // This allows our $source value to remain the same
            @unlink($workPath . $filename);
            @rename( $workPath . 'concat.mpg', $workPath . $filename);
        }

        // Filename for the mp4
        $filename = $dvd->slug . '-trailer-scene' . $scene->id_place . '-' . $format_id . '.mp4';

		// Set encode id
		ffmpeg::setEncodeId($encode_id);
		ffmpeg::source($source);
        ffmpeg::destination($workPath . $filename);
		ffmpeg::set_vids($vid_sizes);
        ffmpeg::process($encode_id);

        // Update tasks report
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Encoding video');

        // Determine volume, put together destination and create folder if necessary
		$volumes = glob(Config::get('ztod.path_trailers') . $site_id . DS . '[0-9]', GLOB_ONLYDIR);
		$volume = end($volumes);
		$volume = basename($volume);
		$dst = Config::get('ztod.path_trailers') . $site_id . DS . $volume . DS . $dvd_id . DS;
		if( !is_dir($dst) ){ Helper::mkdir($dst); }

		// Update task report
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'qt-faststart from ' . $workPath . $filename . ' to ' . $dst . $filename);
        echo 'qt-faststart from ' . $workPath . $filename . ' to ' . $dst . $filename . "\n\r";

		// qt-faststart the file into place, since it's an mp4
		ffmpeg::qtfaststart($workPath . $filename, $dst . $filename);
        //copy($workPath . $filename, $dst . $filename);

		// Update database
		$data = array();
		$data['scene_id'] = $scene_id;
		$data['dvd_id'] = $dvd_id;
		$data['site_id'] = $site_id;
		if( !empty($filename) ){ $data['filename'] = $filename; }
		$data['size'] = @filesize($dst . $filename);
		$data['storage_id'] = 1;
		if( !empty($volume) ){ $data['volume'] = $volume; }
		if( !empty($format_id) ){ $data['format_id'] = $format_id; }
		$data['type'] = 'scene';
		$duration = ffmpeg::getDuration($dst.$filename);
		if( !empty($duration) ){ $data['duration'] = $duration; }

		// Remove anything old
		DB::table('trailers')->where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->delete();
		DB::table('trailers')->insert($data);

		// Remove work folder
		Helper::rmdir($workPath);

		// Finished!
        Tasks::updateTask($task_id, 100, Tasks::STATUS_COMPLETED, 'Complete');

        return;
	}


    /**
     * Cut a trailer into sections and then piece it together into an mp4
     */
    public static function trailer_cut($task_id, $arguments = null){
		// Ensure that we are working with an object
		if( !is_object($arguments) && is_array($arguments) ){
            $arguments = (object)$arguments;
		}

		// Pull params and then convert to an array for passing to other methods
		$scene_id = !empty($arguments->scene_id) ? $arguments->scene_id : null;
		$site_id = !empty($arguments->site_id) ? $arguments->site_id : null;
		$encode_id = !empty($arguments->encode_id) ? $arguments->encode_id : md5(time() . rand(0, 10000000));

        $duration_full = !empty($arguments->duration_full) ? $arguments->duration_full : 120;
        $clip_length = !empty($arguments->clip_length) ? $arguments->clip_length : 120;
        $trim_front = !empty($arguments->trim_front) ? $arguments->trim_front : 90;
        $trim_back = !empty($arguments->trim_back) ? $arguments->trim_back : 90;
		// Convert to array so that it can be passed to the encode::add method for data entry.
		$arguments = (array)$arguments;

        // task has begun
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Beginning to cut trailer');

        $scene = Scene::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->first();
        if( empty($scene) ){
            Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'could not find scene #' . $scene_id . ' in the database');
            return;
        }
        $dvd = Dvd::where('dvd_id', '=', $scene->dvd_id)->where('site_id', '=', $site_id)->first();
        if( empty($dvd) ){
            Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'could not find dvd #' . $scene->dvd_id . ' in the database');
            return;
        }
        $slug = $dvd->slug;

        // retrieve orginal file info
        $original = Scene::getOriginalFile($scene_id, $site_id);
        if( !empty($original) ){
            $source = Config::get('ztod.path_originals') . $site_id . DS . $original->volume . DS . $scene->dvd_id . DS . $original->filename;
            $duration = ffmpeg::getDuration($source);
        }
        else {
            Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, "Unable to find original source file");
            return;
        }
        $format_id = 2;

        $num_clips = floor($duration_full / $clip_length);
        $workPath = path('storage') . 'work' . DS . $encode_id . DS;
        $encode_settings_mpg = Config::get('ztod.ffmpeg_trailer_mpg');
        echo 'Num clips: ' . $num_clips . "\n\r";

        // Get video details
        $videoDetails = ffmpeg::getDetails($source);
        if( empty($videoDetails['srcWidth']) ){ $videoDetails['srcWidth'] = 1920; }
        if( empty($videoDetails['srcHeight']) ){ $videoDetails['srcHeight'] = 1080; }

        // Creates the trailer clips which will be stored in /work/$encode_id
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Generating mpg clips from original source');
        ffmpeg::createClips($duration_full, $clip_length, $trim_front, $trim_back, $encode_settings_mpg, $source, $workPath, $encode_id);

        // Retrieve watermark
        $watermark = self::watermark($site_id, 'scene', 1280, 720);

        // Now concat the clips into one solid MPG with watermark
        $filenameConcat = $workPath . 'concat.mpg';
        $fileList = glob($workPath . '*.mpg');
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Concat individual mpg clips into one file with watermark: ' . $filenameConcat);
        print_r($fileList);
        echo "Concat into one file: " . $filenameConcat . "\n";
        ffmpeg::concat($fileList, $filenameConcat, $watermark, $encode_id);


        // Filename to give trailer
        $filenameMPG = $slug . '-trailer-scene' . $scene->id_place . '-' . $format_id . '.mpg';

        // Retrieve intros
        $intros = self::intros($site_id, 1280, 720);
        $fileList = array();
        if( !empty($intros) ){
            $fileList[] = $intros['in'];
            $fileList[] = $filenameConcat;
            $fileList[] = $intros['out'];

            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Adding Intro and Outro');
            ffmpeg::concat($fileList, $workPath . $filenameMPG, '', $encode_id);
        }
        else {
            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'No intro found. Renameing file ' . $filenameConcat . ' to ' . $workPath . $filenameMPG);
            echo 'Rename: ' . $filenameConcat . ' to ' . $workPath . $filenameMPG . "\n";
            rename($filenameConcat, $workPath . $filenameMPG);
            echo "Rename complete\n\n";
        }

        // Generate tooltips
        $tooltipDestinationPath = Config::get('ztod.path_screenshots') . $site_id . DS . $scene->dvd_id . DS . $scene_id . DS . 'tooltip_trailer' . DS;
        if( is_dir($tooltipDestinationPath) ){ Helper::rmdir($tooltipDestinationPath); }
        Helper::mkdir($tooltipDestinationPath);
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Generating Tooltips');
        echo 'Generating tooltips from ' . $workPath . $filenameMPG . "\n";
        ffmpeg::generateTooltips($workPath . $filenameMPG, $tooltipDestinationPath);

        // Convert to mp4
        $filenameMP4 = $slug . '-trailer-scene' . $scene->id_place . '-' . $format_id . '.mp4';
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Creating mp4 from full mpg trailer');
        $trailerMP4 = Config::get('ztod.ffmpeg_trailer');
        ffmpeg::setEncodeId($encode_id);
        ffmpeg::source($workPath . $filenameMPG);
        ffmpeg::destination($workPath . $filenameMP4);
        ffmpeg::$add_format_to_filename = false;
        ffmpeg::set_vids($trailerMP4);
        ffmpeg::process($encode_id);

        // Location to put encoded trailer
        // Need the volume
        $volumes = glob(Config::get('ztod.path_trailers') . $site_id . DS . '[0-9]', GLOB_ONLYDIR);
        $volume = end($volumes);
        $volume = basename($volume);
        if( empty($volume) ){
            $volume = 1;
        }
        $dst = Config::get('ztod.path_trailers') . $site_id . DS . $volume . DS . $scene->dvd_id . DS;
        if( !is_dir($dst) ){
            Helper::mkdir($dst);
        }

        // qt-faststart the file into place, since it's an mp4
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Using qt faststart to place mp4 into storage');
        ffmpeg::qtfaststart($workPath . DS . $filenameMP4, $dst . $filenameMP4);
        //copy($workPath . DS . $filenameMP4, $dst . $filenameMP4);

        // Update database
        $data = array();
        $data['scene_id'] = $scene->scene_id;
        $data['dvd_id'] = $scene->dvd_id;
        $data['site_id'] = $site_id;
        if( !empty($filenameMP4) ){ $data['filename'] = $filenameMP4; }
        $data['size'] = @filesize($dst . $filenameMP4);
        $data['storage_id'] = 1;
        if( !empty($volume) ){ $data['volume'] = $volume; }
        if( !empty($format_id) ){ $data['format_id'] = $format_id; }
        $data['type'] = 'scene';
        $duration = ffmpeg::getDuration($dst.$filenameMP4);
        if( !empty($duration) ){ $data['duration'] = $duration; }

        // Remove anything old
        DB::table('trailers')->where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->delete();
        DB::table('trailers')->insert($data);

        // Remove work folder
        Helper::rmdir($workPath);

        Tasks::updateTask($task_id, 100, Tasks::STATUS_COMPLETED);
    }

	/**
	 * Encoding::fhg_mpg()
	 * Create X number of mpg clips from scene video
	 *
	 * @param mixed $arguments
	 * @return
	 */
	public static function fhg_mpg($task_id, $arguments = null){
		// Just in case an array gets passed somehow, convert it to an object.
		if( !is_object($arguments) ){
			$arguments = (object)$arguments;
		}
		$num_clips = !empty($arguments->num_clips) ? $arguments->num_clips : 6;
		$scene_id = !empty($arguments->scene_id) ? $arguments->scene_id : null;
		$dvd_id = !empty($arguments->dvd_id) ? $arguments->dvd_id : null;
		$site_id = !empty($arguments->site_id) ? $arguments->site_id : null;
		$encode_id = !empty($arguments->encode_id) ? $arguments->encode_id : md5(time() . rand(0, 10000000));

		// Convert to array so that it can be passed to the encode::add method for data entry.
		$arguments = (array)$arguments;

		// Defaults for clip creation
		$project = 'FHG_MPG';
		$clip_length = Config::get('ztod.fhg_duration'); // 1 minute and 37 seconds
		$trim_duration = 60 * 5;
		$clips = array();
		$random_start_times = true;
		$work_folder = path('storage') . 'work' . DS . $encode_id . DS;
		$log_folder = path('storage') . 'logs' . DS . 'ffmpeg' . DS;
		$encode_settings_mpg = Config::get('ztod.ffmpeg_tube_mpg');
		$debug = false;

		// Begin project by creating db entry into encoding
        Tasks::updateTask($task_id, 0, tasks::STATUS_IN_PROGRESS, 'Creating MPG clips');
		//Encoding::add($encode_id, array('project' => $project, 'status' => 'running', 'currently' => ''), $arguments);

		// Need to retrieve the volume for the files. Retrieve for avi since there is always one.
		$files = DB::table('files')
			->where('scene_id', '=', $scene_id)
			->where('site_id', '=', $site_id)
			->where(function($query){
				$query->or_where('format_id', '=', 1);
				$query->or_where('format_id', '=', 6);
				$query->or_where('format_id', '=', 7);
				$query->or_where('format_id', '=', 8);
				$query->or_where('format_id', '=', 13);
				$query->or_where('format_id', '=', 15);
				$query->or_where('format_id', '=', 16);
				$query->or_where('format_id', '=', 17);
				$query->or_where('format_id', '=', 18);
			})
			->first();
		// Make sure that something was returned before trying to set it.
		if( !empty($files) ){
			$volume = $files->volume;
		}
		else {
			// Update encoding records to indicate there was a problem.
			//self::add($encode_id, array('currently' => 'Failed: no volume folder found', 'status' => 'fail'));
            //tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'Failed: no volume folder found');
			//exit;
            $volume = 8;
		}

		// Path to where clips will be stored.
		$clipFolder = Config::get('ztod.path_tubeclips') . $site_id . DS . $volume . DS . $dvd_id . DS . $scene_id . DS . 'clips' . DS;
		// need a fresh start so wipe out existing files.
		if( is_dir($clipFolder) ){
			Helper::rmdir($clipFolder);
		}

		// Try for the original file first
		$original = Files_Originals::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->first();
		if( !empty($original) ){
			// Compile path to the original file in the storage folder
			$path = Config::get('ztod.path_originals') . $site_id . DS . $original->volume . DS . $dvd_id . DS . $original->filename;
			// Only set file if the file actually exists.
			if( file_exists($path) ){
				$file = $path;
				if( $debug ){ echo $file . ' being used as source.'; }
			}
		}

		// If file is still empty, then try the 1080p version
		if( empty($file) ){
			$encoded = Files::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->where(function($query){
				$query->or_where('format_id', '=', 1);
				$query->or_where('format_id', '=', 6);
				$query->or_where('format_id', '=', 7);
				$query->or_where('format_id', '=', 8);
				$query->or_where('format_id', '=', 13);
				$query->or_where('format_id', '=', 15);
				$query->or_where('format_id', '=', 16);
				$query->or_where('format_id', '=', 17);
				$query->or_where('format_id', '=', 18);
				})->order_by('format_id', 'desc')->first();
			if( !empty($encoded) ){
				// Compile path to storage file
				$path = Config::get('ztod.path_storage') . $site_id . DS . $encoded->volume . DS . $dvd_id . DS . $encoded->filename;
				// Only set file if the file actually exists.
				if( file_exists($path) ){
					$file = $path;
					if( $debug ){ echo $file . ' being used as source.'; }
				}
			}
		}

		// If there is no file set, fail out.
		if( empty($file) ){
            Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'Unable to find original or avi file to encode from');
			echo 'Unable to find original or avi file to encode from';
			exit;
		}


		// Now there should be no problem creating a folder.
		if( !is_dir($clipFolder) ){
			Helper::mkdir($clipFolder);
		}

		// If we have a file to work with, get it's duration
		$duration = ffmpeg::getDuration($file);
		if( $debug ){ echo $file . 'Duration: ' . $duration; }

		// Get the dvd so we have the slug to use in the filename
		$dvd = Dvd::where('dvd_id', '=', $dvd_id)->where('site_id', '=', $site_id)->first();
		if( !empty($dvd) ){
			$dvd_slug = $dvd->slug;
		}
		else {
			$dvd_slug = $dvd_id;
		}
		if( $debug && !empty($dvd_slug) ){
			echo 'DVD found - ' . $dvd_slug . "\n\r";
		}

		// Set the hard start and end, to ensure we are skipping in and out scenes
		$trimmed_start = $trim_duration;
        // forcing the user the last 30 seconds now
		//$trimmed_end = $duration - $trim_duration;

		// We now have a new duration... the length minus the two trimmed bits
		$new_duration = $duration - ($trim_duration * 2);

		$clip_sections = $new_duration / $num_clips;

		// Now clear the db of any clips that may have been previously made for this scene.
		Fhg_clips_mpg::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->delete();

		// Loop over the clip sections
		for($i=0; $i < $num_clips; $i++){
			// Clip #
			$clip = $i + 1;

            // Last clip must be the lsat x seconds of the scene
            if( $clip == $num_clips ){
                $start = $duration - $clip_length;
            }
            else {
                // If we are to use random start times...
                if( $random_start_times ){

                    // Random start time between x and y minute the clip length to ensure that it doesn't exceed the next clip's start point
                    $x = round($trim_duration + ($clip_sections * $clip) - $clip_sections);
                    $y = round($trim_duration + ($clip_sections * ($clip + 1)) - $clip_sections) - $clip_length;
                  
                    $start = rand($x, $y);
                }
                else {
                    $start = round($trim_duration + ($clip_sections * $clip) - $clip_sections);
                }
            }

            // Set start time to be plus or minus 20 seconds to get random length clips
            // This will randomize the plus or minus, then randomize the new start by 0 to 20 seconds, plus or minus.
            $plus_or_minus = rand(1, 2);
            $random_between_values = mt_rand(0, 20);
            // Adjust start time.
            if( $plus_or_minus == 1 ){ $clip_length = $clip_length + $random_between_values; }
            else { $clip_length = $clip_length - $random_between_values; }

            // Add the compiled start and duration times to the array
			$encode_settings_mpg['hd']['params']['ss'] = gmdate('H:i:s', $start);
			$encode_settings_mpg['hd']['params']['t'] = gmdate('H:i:s', $clip_length);
			//$encode_settings_mpg['sd']['params']['ss'] = gmdate('H:i:s', $start);
			//$encode_settings_mpg['sd']['params']['t'] = gmdate('H:i:s', $clip_length);
            echo 'Encoding from ' . gmdate('H:i:s', $start) . "\n\r";

			// Update the database
            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Creating mpg clip #' . $clip . ' starting at ' . gmdate('H:i:s', $start));
			//self::add($encode_id, array('status' => 'running', 'currently' => 'Creating mpg clip #' . $clip . ' starting at ' . gmdate('H:i:s', $start)));

            $filename = "clip-" . $clip . ".mpg";

			// Set the encode id for the folder and tracking txt file
			ffmpeg::setEncodeId($encode_id);
			ffmpeg::source($file);
			if( !is_dir($work_folder) ){ Helper::mkdir($work_folder); }
			ffmpeg::destination($work_folder . $filename);
			ffmpeg::set_vids($encode_settings_mpg);
			ffmpeg::process($encode_id);

			// Puts the new files in their proper places
			Tube::move_clips($clipFolder, $scene_id, $site_id, $dvd_id, $clip, $encode_id, $volume);

			// Insert is done during move_clips, now we can update those records with the timecode
			Fhg_clips_mpg::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->where('place', '=', $clip)->update(array('timecode' => $start));
		}

		// Clean up by removing the txt and work folder.
		if( is_dir($work_folder . $encode_id) ){ Helper::rmdir($work_folder . $encode_id); }
		if( file_exists($log_folder . $encode_id . '.txt') ){ @unlink($log_folder . $encode_id . '.txt'); }

		Encoding::complete($encode_id);

		return true;
	}


	/**
	 * Encoding::fhg_mp4()
	 * Creates the mp4 clips for fhgs and tubes
	 *
	 * @param mixed $arguments
	 * 				- scene_id, site_id, dvd_id, encode_id
	 * @return void
	 */
	public static function fhg_mp4($task_id, $arguments = null){
        $volume = null;
		$watermark = null;
		$vid_sizes = Config::get('ztod.ffmpeg_fhg_mp4');
        $vid_sizes_mpg = Config::get('ztod.ffmpeg_fhg_mpg');

		// Manage arguments
		if( !is_object($arguments) ){ $arguments = (object)$arguments; }
		$scene_id = $arguments->scene_id;
		$site_id = $arguments->site_id;
		$dvd_id = $arguments->dvd_id;
		$encode_id = (!empty($arguments->encode_id)) ? $arguments->encode_id : md5(date('Y-m-d H:i:s') . rand(0,1000000));
		$arguments = (array)$arguments;

        //// MPG CLIPS ////
		// First we need the mpgs, so see if they exist. If not, create them.
		$fhgMPG = Fhg_clips_mpg::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->first();
		// If empty, run the mpg encoding
		if( empty($fhgMPG) ){
			// Run encoding process from /model/encoding.php
			Encoding::fhg_mpg($task_id, $arguments);
		}

		// Get source.. these should be created by now
		// Retrieve the volume
		if( empty($fhgMPG) ){
			$fhgMPG = Fhg_clips_mpg::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->first();
		}

		// This should be set by now using the data in the fhg_clips table
		if( !empty($fhgMPG->volume) ){
			$volume = $fhgMPG->volume;
		}

        // If this is still empty by now, fail.
		if( empty($volume) ){
            $volume = 8;
		}
        //// MPG CLIPS ////

		// Get watermarks based on the vid sizes
        /*
        $watermarkDB = Format_Watermark::where('width', '=', '1280')
                ->where('height', '=', '720')
                ->where('type', '=', 'fhg')
                ->where('site_id', '=', $site_id)
                ->first();
        $watermark = !empty($watermarkDB->watermark) ? Config::get('ztod.path_watermarks') . $watermarkDB->watermark : null;
         * 
         */
        $watermark = self::watermark($site_id, 'fhg', 1280, 720);


		// Set and begin encoding tracker
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'FHG MP4');

		// Source folder should be the HD folder to get the 16:9 clips
		$source = Config::get('ztod.path_tubeclips') . $site_id . DS . $volume . DS . $dvd_id . DS . $scene_id . DS . 'clips' . DS . 'hd' . DS;

		// Destination folders
		$work_folder = path('storage') . 'work' . DS . $encode_id . DS;
		$destination = Config::get('ztod.path_tubeclips') . $site_id . DS . $volume . DS . $dvd_id . DS . $scene_id . DS . 'fhg' . DS;

		// First remove to ensure we're putting in new content
		if( is_dir($work_folder) ){
			Helper::rmdir($work_folder);
		}
		if( !is_dir($work_folder) ){
			Helper::mkdir($work_folder);
		}

		// If there is a folder there already, it needs to be removed.
		if( is_dir($destination) ){
			Helper::rmdir($destination);
		}
		if( !is_dir($destination) ){
			Helper::mkdir($destination);
		}

		// get files from source
		$files = glob($source . '*.mpg');
		if( empty($files) ){
            Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'No files in source folder (' . $source . ')');
			//Encoding::add($encode_id, array('project' => $project, 'status' => 'fail', 'currently' => 'No files in source folder (' . $source . ')'));
			echo 'No files in source folder (' . $source . ')';
			exit;
		}

		// If there are already fhg clips for this one, wipe them out first from the db.
		Fhg_clip::where('scene_id', '=', $scene_id)
			->where('site_id', '=', $site_id)
			->where('dvd_id', '=', $dvd_id)
			->delete();

		// Get the dvd so we have the slug to use in the filename
		$dvd = Dvd::where('dvd_id', '=', $dvd_id)->where('site_id', '=', $site_id)->first();
		if( !empty($dvd) ){
			$dvd_slug = $dvd->slug;
		}
		else {
			$dvd_slug = $dvd_id;
		}

        $filename = $dvd_slug . '-fhg.mpg';

        echo "\r\nVideos: "; print_r($vid_sizes); 
        echo "\r\nWatermarks: "; print_r($watermark);
        echo "\r\n";

        // retrieve (or create) intro/outro if available
        $intros = self::intros($site_id, 1280, 720);
        if( !empty($intros) ){

            // Concat the files into a single long clip
            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Concat all the things');
            ffmpeg::concat($files, $work_folder . 'concat.mpg', $watermark, $encode_id);

            // Create new file using intros and outros as well.
            $fileList = array(
                $intros['in'],
                $work_folder . 'concat.mpg',
                $intros['out']
            );

            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Concat intro/outro onto mpg');
            ffmpeg::concat($fileList, $work_folder . $filename, '', $encode_id);
        }
        // No intro/outro, just concat as normal
        else {
            // Concat the files into a single long clip
            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Concat all the things');
            ffmpeg::concat($files, $work_folder . $filename, $watermark, $encode_id);
        }

        // New filename for mp4
        $filename_mp4 = $dvd_slug . '%d.mp4';

        // Now piece together into mp4 vids
        Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Creating MP4 videos from MPG source: ' . $work_folder . $filename);
        ffmpeg::setEncodeId($encode_id);
        ffmpeg::source($work_folder . $filename);
        ffmpeg::destination($work_folder . $filename_mp4);
        ffmpeg::set_vids($vid_sizes);
        ffmpeg::process($encode_id);

        $scene = Scene::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->first();

        foreach( $vid_sizes as $format => $vid ){
            // New filename, using the formats in the names
            $filename_mp4 = $filename_mp4 = $dvd_slug . '-' . $format . '.mp4';
            $from = $work_folder . $filename_mp4;
            $to = $destination . $filename_mp4;
            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'QT Faststart file "' . $from . ' to ' . $to);

            // QT faststart
            //ffmpeg::qtfaststart($from, $to);
            copy($from, $to);
            echo "qt-faststart file " . $from . " to " . $to ."\n";

            Fhg_clip::insert(array(
                'scene_id' => $scene_id,
                'site_id' => $site_id,
                'dvd_id' => $dvd_id,
                'filename' => $filename_mp4,
                'volume' => $volume,
                'dimensions' => '1280x720',
                'timecode' => 0,
                'place' => $scene->place
            ));
        }

		//Encoding::complete($encode_id);
        Tasks::updateTask($task_id, 100, Tasks::STATUS_COMPLETED, '');

        // remove the world
        //Helper::rmdir($work_folder);

		return true;
	}


	/**
	 * Encoding::tube()
	 * Processes a video into a set of clips and then into a tube clip.
	 *
	 * @param mixed $arguments
	 * @return bool
	 */
	public static function tube($task_id, $arguments = null){

		$project = 'Tube';
		$clip_length = 97; // 1 minute and 37 seconds
		$trim_duration = 60 * 5;
		$num_clips = 6;
		$clips = array();
		$random_start_times = true;
		$work_folder = path('storage') . 'work' . DS;
		$encode_settings_mpg = Config::get('ztod.ffmpeg_tube_mpg');
		$debug = false;

		if( !is_object($arguments) ){
			$arguments = (object)$arguments;
		}

		// If attributes are passed by param, such as by cron
		$scene_id = !empty($arguments->scene_id) ? $arguments->scene_id : null;
		$dvd_id = !empty($arguments->dvd_id) ? $arguments->dvd_id : null;
		$site_id = !empty($arguments->site_id) ? $arguments->site_id : null;
		$tubes = !empty($arguments->tubes) ? $arguments->tubes : null;
		$encode_id = !empty($arguments->encode_id) ? $arguments->encode_id : md5(time() . rand(0, 10000000));
		$file_location = !empty($arguments->file_location) ? $arguments->file_location : null;
        $watermarkPath = Config::get('ztod.path_watermarks');
        if( !is_dir($watermarkPath) ){ Helper::mkdir($watermarkPath); }

		// Convert to array so that it can be passed to the encode::add method for data entry.
		$arguments = (array)$arguments;

		// if nothing, fail out now
		if( empty($scene_id) ){
			echo 'No selections made';
			exit;
		}

        // If not empty, use the dvd slug for the filename
		if( !empty($dvd_id) && !empty($site_id) ){
			$dvd = Dvd::where('dvd_id', '=', $dvd_id)->where('site_id', '=', $site_id)->first();
			$dvd_slug = $dvd->slug . '-';
		}
		else {
			$dvd_slug = 'tube-';
		}

        // Create MPG files
        self::fhg_mpg($task_id, $arguments);

        // Retrieve the volume
        // Empty from before but should have files in it now. So try to retrieve them again.
        $MPG = Fhg_clips_mpg::where('scene_id', '=', $scene_id)->where('site_id', '=', $site_id)->first();

        // This should be set by now using the data in the fhg_clips table
        if( !empty($MPG->volume) ){
            $volume = $MPG->volume;
        }
        else {
            Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'Unable to retrieve volume');
            return;
        }

        // For each tube id that was selected
        foreach( $tubes as $tube_id ){
            // Set vars
            // For encoding updates
            $params = array(
                'site_id' => $site_id,
                'dvd_id' => $dvd_id,
                'scene_id' => $scene_id,
                'tube_id' => $tube_id,
                'encode_id' => $encode_id
            );

            // Update to show that it's running.
            //self::add($encode_id, array('project' => $project, 'status' => 'running'), $arguments);
            Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'checking mpg files');

            // Get tube details now from the database
            $tube = Tube::where('tube_id', '=', $tube_id)->first();
            if( empty($tube) ){
                Tasks::updateTask($task_id, 0, Tasks::STATUS_FAILED, 'Tube id ' . $tube_id . ' not found');
                exit;
            }

            // Piece together folder to store clips. Minus the format. The format (hd || sd) will be added later.
            $clipFolder = Config::get('ztod.path_tubeclips') . $site_id . DS . $volume . DS . $dvd_id . DS . $scene_id . DS . 'clips' . DS;
            $tubeFolder = Config::get('ztod.path_tubeclips') . $site_id . DS . $volume . DS . $dvd_id . DS . $scene_id . DS . 'tubes' . DS;
            if( !is_dir($tubeFolder) ){ Helper::mkdir($tubeFolder); }

             // Figure out which formats we're using
            $formats = glob($clipFolder . '*', GLOB_ONLYDIR);
            foreach( $formats as $format ){
                echo 'Format: ' . $format . "\n\r";
                $subdir = basename($format);
                echo 'Subdir: ' . $subdir . "\n\r";
    
                // Folder for doing work in
                $workPath = path('storage') . 'work' . DS . $encode_id . DS;
                if( !is_dir($workPath) ){ Helper::mkdir($workPath); }

                // Generate watermark
                $size = $encode_settings_mpg[$subdir]['size'];
                $watermark = $watermarkPath . $tube->tube_name . ' - ' . $size . '.png';
                @shell_exec('convert -size ' . $size . ' xc:none -depth 8 ' . $watermarkPath . 'empty.png');
                @shell_exec('convert ' . $watermarkPath . 'empty.png -strokewidth 0 -fill "rgb(0, 0, 0)" -draw "rectangle 0, 650, 1280, 720 " \'' . $watermarkPath . 'bg.png\'');
                @shell_exec('convert -size 1280x70 -background black -gravity east -fill white -pointsize 34 label:"ztod.com/' . $tube->tube_name . '          " "' . $watermarkPath . 'name.png"');
                @shell_exec('composite -geometry +0+650 ' . $watermarkPath . 'name.png ' . $watermarkPath . 'bg.png "' . $watermark . '"');

                // Files to concat
                $files = glob($format . DS . '*.mpg');
                echo 'Files to concat: '; print_r($files); echo "\n\r";

                // Check for intro/outro
                $intros = self::intros($site_id, 1280, 720);
                // if found, add to the mpg
                if( !empty($intros) ){

                    // We need a new concat file with a watermark first before we can add the intro and outro
                    // So create a new concat file to work from
                    $filename = 'tmp.mpg';
                    $source = $workPath . $filename;
                    Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Creating mpg via concat, before adding intro, with watermark: ' . $watermark);
                    ffmpeg::concat($files, $source, $watermark, $encode_id);

                    // New file list to use
                    $fileList = array(
                        $intros['in'],
                        $source,
                        $intros['out']
                    );

                    // Concat the intro and outro onto flie
                    Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Adding intro and outro via concat');
                    ffmpeg::concat($fileList, $workPath . 'concat.mpg', '', $encode_id);

                    // new source file is the concat.mpg
                    $source = $workPath . 'concat.mpg';
                    echo 'New source: ' . $source . "\n\r";
                }
                else {

                    // Encode the mpg
                    Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'No intro, encoding straight to concat.mpg from mpg clips');
                    ffmpeg::concat($files, $workPath . 'concat.mpg', $watermark, $encode_id);

                    // New source to work from
                    $source = $workPath . 'concat.mpg';
                    echo 'New Source: ' . $workPath . "concat.mpg\n\r";
                }

                // Now to create an mp4
                $filename = $dvd_slug . $dvd_id . '-' . $site_id . '-' . $tube->tube_name . '.mp4';

                // Encode
                Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Encoding ' . $filename . ' from ' . $source);
                ffmpeg::setEncodeId($encode_id);
                ffmpeg::source($source);
                ffmpeg::destination($workPath . $filename);
                ffmpeg::set_vids(Config::get('ztod.ffmpeg_tube_mp4'));
                ffmpeg::process($encode_id);

                $source = $workPath . $filename;

                // Final destination for this file
                $destination = $tubeFolder . $tube_id . DS . $subdir . DS;
                if( !is_dir($destination) ){ Helper::mkdir($destination); }

                // QT faststart it
                Tasks::updateTask($task_id, 0, Tasks::STATUS_IN_PROGRESS, 'Using QT Faststart \''. $source . '\' to \'' . $destination . $filename . '\'');
                ffmpeg::qtfaststart($source, $destination . $filename);
                //copy($source, $destination . $filename);

                // Add new hd mp4 into the database
                $data = array(
                    'scene_id' => $scene_id,
                    'site_id' => $site_id,
                    'dvd_id' => $dvd_id,
                    'tube_id' => $tube_id,
                    'format' => $subdir,
                    'filename' => $filename,
                    'volume' => $volume,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                );
                DB::table('tubes_encodes')->where('scene_id', '=', $scene_id)
                        ->where('site_id', '=', $site_id)
                        ->where('tube_id', '=', $tube_id)
                        ->where('format', '=', $format)
                        ->delete();
                DB::table('tubes_encodes')->insert($data);

                //Remove work path so all the work is cleaned up and can begin new
                Helper::rmdir($workPath);
            }
        }

        Tasks::updateTask($task_id, 100, Tasks::STATUS_COMPLETED, '');
    }


    /*
     * Encoding::fixStuckScenes()
     * Finds 'running' Scene projects in the database and checks to see if their progress is at 100%
     * if so, it moves the files into place and marks it as completed.
     *
     * Return @void
     */
    public static function fixStuckScenes(){
        // Look for scenes that are set to running (as in, not complete)
        $projects = self::where('status', '=', 'running')->where('project', '=', 'Scene')->get();

        // If none are found, we're done.
        if( empty($projects) ){
            return;
        }

        // Loop over the projects returned by the query
        foreach( $projects as $project ){
            // Check the progress. If it's 100%, it's likely stuck.
            $progress = self::getProgress($project->encode_id);
            if( $progress == 100 ){
                echo $project->encode_id . ' is stuck!';
                // Moves the files from the work folder (by $id) to the storage volumes and adds to database
                Scene::moveFiles($project->encode_id);
                echo ' - Moved the file(s)';

                // Finish up!
                Encoding::complete($project->encode_id);
                echo ' - Complete!';
            }
        }

        return;
    }

	/**
	 * Encoding::processQueue()
	 * Processes the queue to run encoding on the next available project
	 *
	 * @return void
	 */
	public static function processQueue($project = ''){
		$debug = true;

        // first run and check if any scenes are stuck and needing to be completed.
        self::fixStuckScenes();

		// If no project passed, get all of them.
		if( empty($project) ){
			$projects = self::distinct()->get(array('project'));
		}
		else {
			// Create an array/object to look similar to the db results.
			$projects = array((object)array('project' => $project));
		}

		// Now that we have each type of project, loop over to check for any jobs that need doing
		foreach( $projects as $project ){

			// Check to see if something is already running first.
			/*
			// Also, as an added measure, check to see if it's older than 6 hours. If so, it's likely just stuck. So run the next job.
			$isRunning = self::where('project', '=', $project->project)
						->where('status', '=', 'running')
						->where('created_at', '<', date('Y-m-d H:i:s', mktime(date('h')-6,0,0, date('m'), date('d'), date('Y'))))
						->order_by('id', 'desc')
						->first();
			*/
			// Check for jobs that are scheduled to run next first. If nothing, then check for the next queued item regardless of schedule
			$job = self::where('project', '=', $project->project)
					->where('status', '=', 'queue')
					->where('scheduled', '<=', date('Y-m-d H:i:s'))
					->order_by('scheduled', 'asc')
					->first();

			// If no job was found by schedule...
			if( empty($job) ){
				// No job found by schedule, so retrieve the next job in the queue that has schedule set to null
				$job = self::where('project', '=', $project->project)
						->where('status', '=', 'queue')
						->where('scheduled', '=', '')
						->order_by('id', 'desc')
						->first();
			}

			// If there is nothing in the queue, skip to the next project.
			if( empty($job) ){
				if( $debug ){ echo 'Nothing queued for ' . $project->project . '<br/>'; }
				continue;
			}

			// Get the params from the db to use
			$params = json_decode($job->params);

			// method name to call
			$method = strtolower($project->project);
			self::$method($params);
		}
	}

    /**
     * return or create new intro/outro videos and then return if not already made
     * 
     * @param type $site_id
     * @param type $width
     * @param type $height
     */
    public static function intros($site_id = 1, $width = 1280, $height = 720){
        echo "running intros method \n";
        $work_path = path('storage') . '/work/intros/';
        $orig_path = Config::get('ztod.path_uploads') . 'intro/';
        $file_in = 'in_' . $site_id . '_' . $width . '_' . $height . '.mpg';
        $file_out = 'out_' . $site_id . '_' . $width . '_' . $height . '.mpg';
        $files_exist = false;

        $format = array(
            'hd' => array(
                'size' => $width.'x'.$height,
                'ext' => 'mpg',
                'params' => array(
                    'vcodec' => 'mpeg1video',
                    'preset' => 'medium',
                    'pix_fmt' => 'yuv420p',
                    'b' => '3000000'
                )
            )
        );

        // Have to make sure the work path even begins before we start
        if( !is_dir($work_path) ){
            Helper::mkdir($work_path);
        }

        // If either file does not already exist... we need to make them.
        if( !file_exists($work_path . $file_in) || !file_exists($work_path . $file_out) ){
            // Retrieve intros
            $intros = DB::table('video_intros')->where('site_id', '=', $site_id)->first();
            if( empty($intros) ){
                echo 'Unable to find intro videos for site ' . $site_id . "\n";
                return false;
            }
            else {
                print_r($intros);
            }

            // Original file paths
            $orig_in = $orig_path . $intros->intro;
            $orig_out = $orig_path . $intros->outro;

            // If we're only supposed to grab a portion of the intro
            $format_in = $format;
            if( !empty($intros->intro_duration) ){
                $format_in['hd']['params']['t'] = $intros->intro_duration;
            }

            // Send to ffmpeg
            ffmpeg::$watermarks = null;
            ffmpeg::setEncodeId('intros');
            ffmpeg::set_vids($format_in);
            ffmpeg::source($orig_in);
            ffmpeg::destination($work_path . $file_in);
            ffmpeg::process('intros');

            // If we're only supposed to grab a portion of the intro
            $format_out = $format;
            if( !empty($intros->outro_duration) ){
                $format_out['hd']['params']['t'] = $intros->outro_duration;
            }

            ffmpeg::$watermarks = null;
            ffmpeg::setEncodeId('intros');
            ffmpeg::set_vids($format_out);
            ffmpeg::source($orig_out);
            ffmpeg::destination($work_path . $file_out);
            ffmpeg::process('intros');
        }

        // return the files
        return array(
            'in' => $work_path . $file_in,
            'out' => $work_path . $file_out
        );
    }

    /**
     * Either returns a watermark path/filename or it creates a watermark to use
     * 
     * @param type $site_id
     * @param type $type
     * @param type $width
     * @param type $height
     * @return string
     */
    public static function watermark($site_id = 1, $type = 'scene', $width = 1920, $height = 1080){
        // Initialize vars
        $watermarkPath = Config::get('ztod.path_watermarks');
        $watermarkHeight = 70;
        $sitesFieldToUseForWatermark = 'site_url';
        $watermark = null;

		// Retrieve watermark based on site, width and height. 
        echo 'Looking for watermark in database: ' . $width . 'x' . $height . "\n";
        $watermarkDB = Format_Watermark::where('site_id', '=', $site_id)
                ->where('width', '=', $width)
                ->where('height', '=', $height)
                ->where('type', '=', $type)
                ->first();

        // If there is a watermark in the db
        if( !empty($watermarkDB) ){
            if( !file_exists($watermarkPath . $watermarkDB->watermark) ){
                echo "Watermark found in database but file does not exist: " . $watermarkPath . $watermarkDB->watermark . "\n";
            }
            else {
                $watermark = $watermarkPath . $watermarkDB->watermark;
                echo 'Watermark found in database: ' . $watermark . "\n";
            }
        }

        // If no watermark is found, create a default one
        if( empty($watermark) || !$watermark ){
            echo "No watermark found. Creating one... \n";
            $size = $width .'x'. $height;
            $watermark_height = 70;
            $width = $width;
            $height = $height - $watermark_height;

            // Get site details. We'll need the name and watermark info
            $site = Site::where('site_id', '=', $site_id)->first();

            // Set the final path and filename to be used for this watermark
            $watermark = $watermarkPath . $site->site_name . ' - ' . $size . '.png';

            // Create empty png set to the size of the video
            @shell_exec('convert -size ' . $size . ' xc:none -depth 8 ' . $watermarkPath . 'empty.png');
            echo 'convert -size ' . $size . ' xc:none -depth 8 ' . $watermarkPath . 'empty.png' . "\n";

            // Create the watermark portion. width x watermarkHeight with the text from the sites database
            @shell_exec('convert -size '.$width.'x'.$watermarkHeight.' -background transparent -depth 8 -gravity east -fill black -pointsize 34 label:"' . $site->site_url . '          " "' . $watermarkPath . $site->site_name . '.png"');
            echo 'convert -size '.$width.'x'.$watermarkHeight.' -background transparent -depth 8 -gravity east -fill black -pointsize 34 label:"' . $site->site_url . '          " "' . $watermarkPath . $site->site_name . '.png"' . "\n";

            // Composite the two pngs into one which will be our final watermark
            @shell_exec('composite -geometry +0+'.$height.' "' . $watermarkPath . $site->site_name . '.png" "' . $watermarkPath . 'empty.png" "' . $watermark . '"');
            echo 'composite -geometry +0+'.$height.' "' . $watermarkPath . $site->site_name . '.png" "' . $watermarkPath . 'empty.png" "' . $watermark . '"' . "\n";
        }

        echo "Using watermark: " . $watermark . "\n";
        return $watermark;
    }
}
