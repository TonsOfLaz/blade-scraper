<?php namespace TonsOfLaz\BladeScraper;

use View;
use Illuminate\Database\Eloquent\Model;

class BlankBladeScraperModel extends Model
{

}

class BladeScraper
{
	protected $html;
    protected $view;
    protected $original_view_path;

	protected $view_markers;
    protected $generated_view_markers;
	protected $html_markers;
	
	protected $view_marker_head;
	protected $html_marker_head;
	protected $reached_end;

	protected $left_side;
	protected $right_side;

	protected $assumptions;
    protected $variable_assumptions;
	protected $blade_blocks;

	protected $current_scope;
    protected $last_scope;
	protected $current_variable;
    protected $queue;
    protected $red_herrings;

    protected $string_to_eval;


	public function extract($viewname, $html)
    {

        ini_set('pcre.jit', false);
        //ini_set("pcre.recursion_limit", "10000");
        //ini_set('memory_limit', '512M');
        //dd(ini_get('memory_limit'));
        ini_set('pcre.backtrack_limit', 2000000);

        // If a url is passed, get the contents of the url
        if (filter_var($html, FILTER_VALIDATE_URL)) {
            $html = file_get_contents($html);
            //dd($html);
        }

        $this->initializeScraperValues($viewname, $html);
        //dd($this->blade_blocks->view_blocks);

        $regex_sections = $this->runRegexesRecursive('root', $this->html);

        //dd($regex_sections, "Laz");

        $data = $this->getDataFromSections('root', $regex_sections['root'], null);

        if (count($data) < 1) {
            //print_r($regex_sections);
            throw new \Exception("NO DATA RETURNED");
            //dd($regex_sections, "NO DATA RETURNED");
        }

        $this->generateHtmlFromDataToVerifyItMatches();
        //dd($data);
        return $data;
        
    }

    public function generateHtmlFromDataToVerifyItMatches()
    {
        $tempviewpath = preg_replace('|(.*?)\.blade|', 'temp_blade_scraper_view.blade', basename($this->original_view_path));
        
        $tempviewpath = str_replace(basename($this->original_view_path), $tempviewpath, $this->original_view_path);
        //dd($tempviewpath);
        
        //dd($tempview);
        $tempviewfile = str_replace(['{{', '}}'], ['{!!', '!!}'], $this->view);

        
        $contents = '';
        try {
            file_put_contents($tempviewpath, $tempviewfile);

            //$scraper_view = new View;

            View::addLocation(dirname($this->original_view_path));
            $feedback = View::make('temp_blade_scraper_view', $data);

            $contents = $feedback->render();
            //fclose($temp); // this removes the file
        } catch (\Exception $e) {
            echo "ERROR:";
            
            //dd($e, $regex_sections, $data);
        }
        unlink($tempviewpath);
        

        $contents = $this->stripWhiteSpace($contents);
        //$contents = html_entity_decode($contents);
        if (isset($data['ignore_beginning'])) {
            $contents = $data['ignore_beginning'].$contents;
            unset($data['ignore_beginning']);
        }
        if (isset($data['ignore_end'])) {
            $contents .= $data['ignore_end'];
            unset($data['ignore_end']);
        }
        if ($this->html != $contents) {
            //dd($positions);
            for ($i = 0; $i < strlen($this->html); $i++) {
                $html_sub = substr($this->html, 0, $i);
                $contents_sub = substr($contents, 0, $i);
                if ($html_sub != $contents_sub) {
                    $contents_mismatch = substr($contents, $i - 50, 150);
                    $html_mismatch = substr($this->html, $i - 50, 150);
                    echo "PROBLEM.";
                    throw new \Exception;
                    //dd($this->html, $data, "OLD: ".$html_mismatch, "NEW: ".$contents_mismatch, "Ya done fucked up.");
                }
            }
            
        }
    }
    

    public function stripWhiteSpace($html)
    {
        //$html = preg_replace('~>\s+<~', '><', $html);
        $html = preg_replace('~>\s+~', '>', $html);
        $html = preg_replace('~\s+<~', '<', $html);
        $html = preg_replace('~\s+@~', '@', $html);
        $html = preg_replace('~[\n\t]+~', '', $html);
        $html = preg_replace('~\s+~', ' ', $html);
        return $html;
    }
    public function initializeScraperValues($viewname, $html)
    {
    	$this->current_scope = '@root_1';
        $this->last_scope = '@root_1';

    	$html = $this->stripWhiteSpace($html);
        $this->html = $html;
        //dd($this->html);

        $html_tag_regex = '(\<.*?\>)|(\s)';
        $html_split = preg_split('/'.$html_tag_regex.'/', $html, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_OFFSET_CAPTURE|PREG_SPLIT_NO_EMPTY);
        
        $html_markers = collect([]);
        //dd($html_split);
        foreach ($html_split as $marker) {
            $marker_location = $marker[1];
            $marker_text = $marker[0];
            $html_markers[$marker_location] = $marker_text;
        }
        

    	$viewpath = str_replace('.', '/', $viewname);
    	$filepath = 'resources/views/'.$viewpath.".blade.php";

        if (file_exists($filepath)) {
            $view = file_get_contents($filepath);
            $this->original_view_path = $filepath;
        } else if (file_exists($viewname)) {
            $view = file_get_contents($viewname);
            $this->original_view_path = $viewname;
        } else {
            dd("View file not found: ".$viewname);
        }
        $this->view = $view;
    	
    	$view = $this->stripWhiteSpace($view);
        $view = str_replace('{!!', '{{', $view);
        $view = str_replace('!!}', '}}', $view);
        $view = '@root'.$view.'@endroot';
        //dd($view);

        

        //$block_pattern = '(\@root)|(\@endroot)|(\@foreach\s?\(\$.*? as \$.*?\))|(\@endforeach)|(\@endif)|(\@if\s?\(\s?\$.*?\s?\))';
        $block_pattern = '\@root|\@endroot|\@foreach\s?\(\$.*? as \$.*?\)|\@endforeach|\@endif|\@if\s?\(\s?\$.*?\s?\)';
        // $foreach_pattern = '\@foreach\s?\(\$.*? as \$.*?\)|\@endforeach';
        // $if_pattern = '\@if\s?\(\s?\$.*?\s?\)|\@endif';
        // $handles_pattern = '{{.*?}}';
        $split_tags = '(\<.*?\>)';
        $variable_handles = '(\{\{.*?\}\})';
        $spaces = '(\s)';
        //$master_pattern = $root_pattern.'|'.$foreach_pattern.'|'.$if_pattern.'|'.$split_tags;
        //$master_pattern = $master_pattern.'|'.$handles_pattern;
        $master_pattern = '/'.$split_tags.'|'.$block_pattern.'|'.$variable_handles.'|'.$spaces.'/';

        $just_html = preg_split($master_pattern, $view, -1, PREG_SPLIT_OFFSET_CAPTURE|PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
        //dd($just_html);
        $view_markers = collect([]);
        foreach ($just_html as $marker) {
            $marker_location = $marker[1];
            $marker_text = $marker[0];
            $view_markers[$marker_location] = $marker_text;
        }
        //dd($view_markers);
        
        $this->html_markers = $html_markers;
        $this->view_markers = $view_markers;

        $this->generated_view_markers = collect([]);
        $this->red_herrings = collect([]);

        $this->blade_blocks = new BladeScraperBlocks($view);

        //dd($this->blade_blocks->view_blocks);
        //$this->assumptions = new BladeScraperAssumptions($this->blade_blocks, $this->view_markers);
        //dd($this->blade_blocks->view_blocks);

        return;


    }

    public function cleanUpMatches($matches) 
    {
        $cleanmatches = [];

        foreach ($matches as $matchkey => $matchval) {
            //dd($matchval);
            if (is_numeric($matchkey)) {
                continue;
            }
            $matcharr = explode('ARROW', $matchkey);
            $scope = $matcharr[0];
            if (isset($matches['scope_'.$scope])) {
                $scope_text = $matches['scope_'.$scope];
                if (str_contains($scope_text, $matchval)) {
                    $cleanmatches[$matchkey] = $matchval;
                } else {
                    //dd($scope_text, $matchkey);
                    $cleanmatches[$matchkey] = '';
                }
            } else {
                $cleanmatches[$matchkey] = $matchval;
            } 
            
        }
        return $cleanmatches;
    }

    public function runRegexesRecursive($blockid, $html) 
    {
        //dd($this->blade_blocks->view_blocks);
        //dd($this->html);
        $regex_sections = [];
        $regex_sections[$blockid]['html'] = $html;
    
        $curr_regex = $this->blade_blocks->view_blocks['@'.$blockid]['regex'];
        $generic_regex = $this->blade_blocks->view_blocks['@'.$blockid]['generic_regex'];

        if (str_contains($blockid, 'foreach')) {
            //$scope_regex = preg_replace('|\[\[\[ \@(.*?) \]\]\]|', '(?P<$1>.*?)', $curr_regex);

            //dd($curr_regex);
            //dd($scope_regex);
            preg_match_all('/\[\[\[ \@(.*?) \]\]\]/', $curr_regex, $child_blockids);
            foreach($child_blockids[1] as $child_blockid) {   
                //dd($blockid);
                $curr_regex = $this->getDeepRegex($curr_regex, $child_blockid);
                
            }
            while (preg_match('/\[\[\[ \@(.*?) \]\]\]/', $curr_regex)) {

                preg_match_all('/\[\[\[ \@(.*?) \]\]\]/', $curr_regex, $inner_child_blockids);

                //dd($child_blockids);
                foreach($inner_child_blockids[1] as $child_blockid) {   
                    //dd($blockid);
                    $curr_regex = $this->getShallowRegex($curr_regex, $child_blockid);
                    
                }
            }

            if ($blockid == 'foreach1') {
                //dd($html, $curr_regex);
            }

            //$scope_regex = str_replace('<BLOCK_if2> &nbsp;', '<BLOCK_if2>&nbsp;', $scope_regex);
            $i = 1;
            //dd($scope_regex);
            // $html variable gets shortened each time
            while (preg_match('|('.$curr_regex.'){1}|', $html, $loopmatches)) {

                $scope_html = $loopmatches[0];

                if ($blockid == 'foreach4') {
                    //dd($scope_html);
                    //dd($html, $curr_regex, $generic_regex, $scope_regex);
                }
                
                // if ($i == 3 && $blockid == 'foreach1') {
                //     dd($loopmatches, $scope_regex);
                // }
                
                //dd($scope_html);
                $html = substr($html, strlen($scope_html), strlen($html) - 1);

                $loopmatches = $this->cleanUpMatches($loopmatches);

                $regex_sections[$blockid]['loops'][$i]['html'] = $scope_html;
                
                $regex_sections[$blockid]['loops'][$i]['matches'] = $loopmatches;

                foreach($child_blockids[1] as $child_blockid) {  
                    if (!isset($loopmatches['BLOCK_'.$child_blockid])) {
                        //dd($scope_html, $scope_regex);
                        //continue;

                        $loopmatches['BLOCK_'.$child_blockid] = '';
                        $regex_sections[$blockid]['loops'][$i]['matches'] = $loopmatches;
                        
                    }
                    //dd($scope_html, $loopmatches);
                    $child_html = $loopmatches['BLOCK_'.$child_blockid];

                    $child_section = $this->runRegexesRecursive($child_blockid, $child_html);

                    $regex_sections[$blockid]['loops'][$i]['children'][] = $child_section;
                    
                    //dd($loopmatches, $child_blockid, $child_html);
                }
                
                //dd($child_blockids);

                $i++;
            }
            $regex_sections[$blockid]['regex'] = $curr_regex;
            //$regex_sections[$blockid]['loops'][$i]['html'] = $scope_html;

            //dd($regex_sections, $scope_regex);
        } else {

            //dd($scope_regex);

            //dd($curr_regex);
            preg_match_all('/\[\[\[ \@(.*?) \]\]\]/', $curr_regex, $child_blockids);

            //dd($child_blockids, $curr_regex);
            foreach($child_blockids[1] as $child_blockid) {   
                //dd($blockid);
                $curr_regex = $this->getDeepRegex($curr_regex, $child_blockid);
                
            }

            //dd($curr_regex);

            while (preg_match('/\[\[\[ \@(.*?) \]\]\]/', $curr_regex)) {

                preg_match_all('/\[\[\[ \@(.*?) \]\]\]/', $curr_regex, $inner_child_blockids);

                //dd($child_blockids);
                foreach($inner_child_blockids[1] as $child_blockid) {   
                    //dd($blockid);
                    $curr_regex = $this->getShallowRegex($curr_regex, $child_blockid);
                    
                }
            }
            //dd($curr_regex);
            //dd($html, $curr_regex);
            // if any nested if blocks
            //$curr_regex = preg_replace('|\[\[\[ \@.*? \]\]\]|', '.*?', $curr_regex);
            //dd($temp_regex, $curr_regex);
            // Start and end anchors 5/30
            
            //echo $html."\n\n";
            //echo $curr_regex;
            preg_match('|^'.$curr_regex.'$|', $html, $matches);
            //dd($blockid);
            //dd($curr_regex);
            if (preg_last_error()) {
                //print_r($html);
                //print_r('|^'.$curr_regex.'$|');
                throw new \Exception($this->preg_errtxt(preg_last_error()));
            }

            $regex_sections[$blockid]['matches'] = $this->cleanUpMatches($matches);
            //dd($child_blockids);
            //dd($regex_sections);
            foreach($child_blockids[1] as $child_blockid) {  
                //dd($matches);

                if (!isset($matches['BLOCK_'.$child_blockid])) {
                    //dd($scope_html, $scope_regex);
                    //continue;
                    //dd($matches, $regex_sections);
                    $matches['BLOCK_'.$child_blockid] = '';
                    $regex_sections[$blockid]['matches'] = $matches;
                    
                }
                $child_html = $matches['BLOCK_'.$child_blockid];

                $child_section = $this->runRegexesRecursive($child_blockid, $child_html);
                //dd($regex_sections);
                $regex_sections[$blockid]['children'][] = $child_section;
                            
            }

            $regex_sections[$blockid]['regex'] = $curr_regex;
        }

        return $regex_sections;
    }

    public function getDeepRegex($curr_regex, $child_blockid)
    {
        $childregex = $this->blade_blocks->view_blocks['@'.$child_blockid]['regex'];
        $childgenericregex = $this->blade_blocks->view_blocks['@'.$child_blockid]['generic_regex'];
        $childregex = preg_replace('|\?P\<.*?\>|', '', $childregex);
        $childregex = trim($childregex);
        
        if (str_contains($child_blockid, 'if')) {
            // Added + to make it possessive, which stops recursion and avoid segmentation fault 11
            $curr_regex = preg_replace('|\[\[\[ \@'.$child_blockid.' \]\]\]|', '(?P<BLOCK_'.$child_blockid.'>('.$childregex.')?+)', $curr_regex);
            //dd($curr_regex);
        } else {
            // Added + to make it possessive, which stops recursion and avoid segmentation fault 11
            $curr_regex = preg_replace('|\[\[\[ \@'.$child_blockid.' \]\]\]|', '(?P<BLOCK_'.$child_blockid.'>('.$childregex.')*+)', $curr_regex);
        }
        return $curr_regex;
    }
    public function getShallowRegex($curr_regex, $child_blockid)
    {
        $curr_regex = preg_replace('|\[\[\[ \@'.$child_blockid.' \]\]\]|', '(.*?)', $curr_regex);
        return $curr_regex;
    }
    public function getDataFromSections($sectionid, $section, $model)
    {
        $data = [];
        foreach ($section['matches'] as $matchvar => $matchval) {
            if (starts_with($matchvar, 'BLOCK_')) {
                continue;
            }
            if ($model) {
                $temparr = explode('ARROW', $matchvar);

                if (isset($temparr[1])) {
                    $varname = $temparr[1];
                    if (!isset($model->$varname)) {
                        // It may already be set
                        $model->$varname = $matchval;
                    }
                    
                } else {
                    dd('weird scope with '.$matchvar.', should be $object->'.$matchvar);
                }

                $scopemodel = $temparr[0];
                if ($model->scraper_model_name != $scopemodel) {
                    $model->send_to_parent_scope = ['modname' => $temparr[0],
                                                    'varname' => $temparr[1],
                                                    'val'     => $matchval];
                }
                
                
                
            } else {
                $data[$matchvar] = $matchval;
            }
            
        }
        
        //dd($sectioninfo['children']);
        if (isset($section['children'])) {
            foreach ($section['children'] as $key => $childarr) {
                foreach ($childarr as $childid => $childinfo) {
                    $block = $this->blade_blocks->view_blocks['@'.$childid];
                    if (starts_with($childid, 'foreach')) {
                        $models = collect([]);
                        $plural = $block['command']['plural'];
                        if (isset($childinfo['loops'])) {
                            //dd($childinfo['loops']);
                            foreach ($childinfo['loops'] as $oneloop) {
                                //dd($oneloop, $childid);
                                //dd($this->blade_blocks->variable_parents);
                                $blankBladeScrapermodel = new BlankBladeScraperModel;
                                $blankBladeScrapermodel->scraper_model_name = $block['command']['singular'];
                                
                                $plural = $block['command']['plural'];
                                //dd($plural);

                                $models[] = $this->getDataFromSections($childid, $oneloop, $blankBladeScrapermodel);
                                if ($blankBladeScrapermodel->send_to_parent_scope) {
                                    $modelname = $blankBladeScrapermodel->send_to_parent_scope['modname'];
                                    $varname = $blankBladeScrapermodel->send_to_parent_scope['varname'];
                                    $val = $blankBladeScrapermodel->send_to_parent_scope['val'];
                                    if ($model->scraper_model_name == $modelname) {
                                        $model->$varname = $val;
                                    } else {
                                        $model->send_to_parent_scope = $blankBladeScrapermodel->send_to_parent_scope;
                                    }
                                    //dd($model);
                                    unset($blankBladeScrapermodel->send_to_parent_scope);
                                }
                            }
                        }
                        if ($model) {
                            $model->$plural = $models;
                        } else {
                            $data[$plural] = $models;
                        }
                    }
                    if (starts_with($childid, 'if')) {
                        $booleanval = false;
                        //dd($childinfo);
                        if (strlen($childinfo['html']) > 0) {
                            $booleanval = true;
                        }
                        $boolean = $block['command']['boolean'];
                        if ($model) {
                            $model->$boolean = $booleanval;
                            $model = $this->getDataFromSections($childid, $childinfo, $model);
                        } else {
                            //print_r($childinfo);

                            foreach ($childinfo['matches'] as $varname => $varval) {
                                if (starts_with($varname, 'BLOCK_')) {
                                    continue;
                                }
                                $data[$varname] = $varval;
                            }

                            $data[$boolean] = $booleanval;
                            //dd($data);
                            $data = array_merge($data, $this->getDataFromSections($childid, $childinfo, null));

                        }
                        
                    }
                }
            }
        }
        if ($model) {
            return $model;
        }
        return $data;
    }
       
    public function preg_errtxt($errcode)
    {
        static $errtext;

        if (!isset($errtxt))
        {
            $errtext = array();
            $constants = get_defined_constants(true);
            foreach ($constants['pcre'] as $c => $n) if (preg_match('/_ERROR$/', $c)) $errtext[$n] = $c;
        }

        return array_key_exists($errcode, $errtext)? $errtext[$errcode] : NULL;
    } 


}



