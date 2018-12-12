<?php namespace TonsOfLaz\BladeScraper;

use View;
use Goutte;
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
        //dd($data);
        return $data;
        
    }
    public function getCodeToEval($uid) {
        //echo $uid."\n";

        if (starts_with($uid, '@foreach')) {
            $plural = $this->final_details[$uid]['plural'];
            $singular = $this->final_details[$uid]['singular'];
            $parent = $this->final_details[$uid]['parent'];
            $this->str_to_eval .= '$'.$plural.' = collect([]);'."\n";

            $loops = $this->final_details[$uid]['loops'];
            for ($i = 0; $i < $loops; $i++) {
                
                $this->str_to_eval .= '$'.$singular.' = new BlankBladeScraperModel;'."\n";

                if (isset($this->final_details[$uid]['variables'])) {
                    $varcount = count($this->final_details[$uid]['variables']);
                    //dd($varcount);
                    foreach ($this->final_details[$uid]['variables'] as $vkey => $variable_pair) {
                        //dd($variable_pair);
                        //dd($vkey, $i, $loops);
                        
                        $start = $i * ($varcount / $loops);
                        $end = ($i + 1) * ($varcount / $loops);
                        //$this->str_to_eval .= "LOOPS: $i, $vkey, $start, $end\n";

                        if ($vkey >= $start && $vkey < $end) {
                            foreach ($variable_pair as $varname => $varval) {
                                $varval = addslashes($varval);
                                $varname = trim(str_replace(['{{', '}}', '{!!', '!!}'], '', $varname));
                                $this->str_to_eval .= $varname.' = \''.$varval.'\''.";\n";
                            }
                        }
                    }
                }
                if (isset($this->assumptions->uids[$uid]['children'][$i])) {
                    
                    foreach ($this->assumptions->uids[$uid]['children'][$i] as $childuid => $dud2) {
                        $this->getCodeToEval($childuid);    
                    }
                }
                $this->str_to_eval .= '$'.$plural.'[] = $'.$singular.';'."\n";
            }
            
            if ($parent) {
                $this->str_to_eval .= '$'.$parent.'->'.$plural.' = $'.$plural.";\n";
            } else {
                $this->str_to_eval .= '$data[\''.$plural.'\'] = $'.$plural.";\n";
            }
            return;
            
        } elseif (starts_with($uid, '@if')) {
            $boolean = $this->final_details[$uid]['boolean'];
            if ($parent = $this->final_details[$uid]['parent']) {
                $boolean = $parent.'->'.$boolean;
                $this->str_to_eval .= '$'.$boolean.' = '.(int)$this->final_details[$uid]['val'].';'."\n";
            } else {
                $boolean = str_replace('$', '', $boolean);
                $this->str_to_eval .= '$data[\''.$boolean.'\'] = '.(int)$this->final_details[$uid]['val'].';'."\n";
                
            }
            
   
        } 
        if (isset($this->final_details[$uid]['variables'])) {
            //dd($varcount);
            foreach ($this->final_details[$uid]['variables'] as $vkey => $variable_pair) {
                foreach ($variable_pair as $varname => $varval) {
                    $varval = addslashes($varval);
                    $varname = trim(str_replace(['{{', '}}', '{!!', '!!}'], '', $varname));
                    if (str_contains($varname, '->')) {
                        $this->str_to_eval .= $varname.' = \''.$varval.'\''.";\n";
                    } else {
                        $varname = str_replace('$', '', $varname);
                        $this->str_to_eval .= '$data[\''.$varname.'\'] = \''.$varval.'\''.";\n";
                    }
                    
                }
            }
        }
        if (isset($this->assumptions->uids[$uid]['children'])) {
            foreach ($this->assumptions->uids[$uid]['children'] as $childuid => $dud) {
                $this->getCodeToEval($childuid);
            }
        }
        
    }
    public function hardcodeFinalVariables()
    {
        $bills = collect([]);

        $bill = new BlankBladeScraperModel;
        $bill->title = 'An Act to be awesome.';
        $bill->is_docket = true;
        $bill->docket_number = 42;

            $actions = collect([]);
            
            $action = new BlankBladeScraperModel;
            $action->date = '2017-03-14';
            $action->note = 'Filed in the House';
            $actions[] = $action;

            $action = new BlankBladeScraperModel;
            $action->date = '2017-03-15';
            $action->note = 'Amended and rewritten';
            $action->laz = true;

                $whats = collect([]);
                $what = new BlankBladeScraperModel;
                $what->now = 'Yeah i got something to say';
                $whats[] = $what;
                $what = new BlankBladeScraperModel;
                $what->now = 'Cause why not?';
                $whats[] = $what;
                $action->whats = $whats;

            $actions[] = $action;

            $action = new BlankBladeScraperModel;
            $action->date = '2017-03-16';
            $action->note = 'Withdrawn in the House';
            $actions[] = $action;

        $bill->actions = $actions;

            $cosponsors = collect([]);

            $cosponsor = new BlankBladeScraperModel;
            $cosponsor->id = 22;
            $cosponsor->name = 'Joey James';
            $cosponsor->district = 'District 4';
            $bill->samevalue = 'Bill value';
            $cosponsors[] = $cosponsor;

            $cosponsor = new BlankBladeScraperModel;
            $cosponsor->id = 23;
            $cosponsor->name = 'James Joseph';
            $cosponsor->district = 'District 5';
            $bill->samevalue = 'Bill value';
            $cosponsors[] = $cosponsor;

            $cosponsor = new BlankBladeScraperModel;
            $cosponsor->id = 24;
            $cosponsor->name = 'Ray Jones';
            $cosponsor->district = 'District 6';
            $bill->samevalue = 'Bill value';
            $cosponsors[] = $cosponsor;

            $cosponsor = new BlankBladeScraperModel;
            $cosponsor->id = 24;
            $cosponsor->name = 'Ray Jones';
            $cosponsor->district = '';
            $bill->samevalue = 'Bill value';
            $cosponsors[] = $cosponsor;

        $bill->cosponsors = $cosponsors;

        $bills[] = $bill;

        $bill = new BlankBladeScraperModel;
        $bill->title = 'An Act to be second.';
        $bill->is_docket = true;
        $bill->docket_number = 44;

            $actions = collect([]);
            
            $action = new BlankBladeScraperModel;
            $action->date = '2017-03-14';
            $action->note = 'Filed in the House';
            $actions[] = $action;

            $action = new BlankBladeScraperModel;
            $action->date = '2017-03-15';
            $action->note = 'Amended and rewritten';
            $actions[] = $action;

        $bill->actions = $actions;

            $cosponsors = collect([]);

            $cosponsor = new BlankBladeScraperModel;
            $cosponsor->id = 22;
            $cosponsor->name = 'Henry Hames';
            $cosponsor->district = 'District 4';
            $bill->samevalue = 'Bill value';
            $cosponsors[] = $cosponsor;

            $cosponsor = new BlankBladeScraperModel;
            $cosponsor->id = 23;
            $cosponsor->name = 'Fred Frederickson';
            $cosponsor->district = 'District 5';
            $bill->samevalue = 'Bill value';
            $cosponsors[] = $cosponsor;

        $bill->cosponsors = $cosponsors;

        $bills[] = $bill;

        $data = ['page_title'       => 'Bills By Some Jerk',
                 'has_section'      => true,
                 'section'          => '<div>This is a section</div>',
                 'has_sponsor'      => true,
                 'sponsor'          => 'Rep. Laz',
                 'attorney_general' => true,
                 'bills'            => $bills];

        return $data;
    }
    public function hardcodedFinalAssumptions()
    {
        $this->setBooleanTrue('@if1_1');
        $this->setBooleanTrue('@if2_1');
        $this->setBooleanTrue('@if3_1');
        $this->addLoop('@foreach1_1');
        
        $this->setBooleanTrue('@if4_1');
        $this->addLoop('@foreach2_1');
        $this->addLoop('@foreach2_1');
        $this->setBooleanTrue('@if5_2');
        $this->addLoop('@foreach3_2');
        $this->addLoop('@foreach3_2');
        $this->addLoop('@foreach2_1');

        $this->addLoop('@foreach4_1');
        $this->addLoop('@foreach4_1');
        $this->addLoop('@foreach4_1');
        $this->addLoop('@foreach4_1');

        $this->addLoop('@foreach1_1');
        $this->setBooleanTrue('@if4_2');
        $this->addLoop('@foreach2_2');
        $this->addLoop('@foreach2_2');
        $this->addLoop('@foreach4_2');
        $this->addLoop('@foreach4_2');

        
        $trial = $this->generateAndTry();
        dd( $this->variable_assumptions,
            $this->assumptions->uids, 
            $this->left_side, 
            $this->right_side, 
            "CURR: ".$this->current_scope, 
            "LAST: ".$this->last_scope, 
            $trial );
    }
    public function addChildrenToQueue($uid) 
    {
        $children = $this->assumptions->getChildrenByUid($uid);
        if ($uid == '@foreach1_1'){
            //dd($children);
        }
        //dd($children);
        $this->queue = $this->queue->merge(array_reverse($children));
        return $children;
    }
    public function tryUid($uid)
    {
        $one_assumption = [];
        if (starts_with($uid, '@if')) {
            $one_assumption = $this->setBooleanTrue($uid); 
        }
        if (starts_with($uid, '@foreach')) {
            $one_assumption = $this->addLoop($uid);  
        }
        return $one_assumption;
    }
    public function tryRandomUid()
    {
        //dd($this->assumptions->uids);
        $uid = '@root_1';
        while ($uid == '@root_1') {
            $uid = array_rand($this->assumptions->uids);    
        }
        
        //dd($uid);
        $this->tryUid($uid);
    }
    public function moveBothSidesForward()
    {
    	$this->moveLeftSideForward();
        $this->moveRightSideForward();
    	
    }
    public function moveLeftSideForward()
    {

        $this->view_marker_head += 1;

    	if (isset($this->generated_view_markers[$this->view_marker_head])) {

            $view_marker_arr = $this->generated_view_markers[$this->view_marker_head];
            
            $view_loc    = $view_marker_arr['loc'];
            $view_marker = $view_marker_arr['val'];
            $scope       = $view_marker_arr['uid'];

            $this->left_side[] = $view_marker;
            $this->last_scope = $this->current_scope;
            $this->current_scope = $scope;

            if ($this->generated_view_markers[$this->view_marker_head] == ' ') {
                //$this->moveLeftSideForward();
            }
            //dd($this->current_scope);
            
            return;

            
    	}
    	$this->reached_end = true;
    	return;
    }
    public function moveRightSideForward()
    {
    	foreach ($this->html_markers as $html_loc => $html_marker) {
    		if ($html_loc > $this->html_marker_head) {
    			$this->html_marker_head = $html_loc;
    			$this->right_side[] = $html_marker;
    			return;			
    		}
    	}
    	$this->reached_end = true;
    	return;
    }
    public function moveLeftSideBack()
    {
    	$this->left_side->pop();
    	$this->view_marker_head -= 1;

        if (isset($this->generated_view_markers[$this->view_marker_head])) {
            $view_marker_arr = $this->generated_view_markers[$this->view_marker_head];
            
            $view_loc    = $view_marker_arr['loc'];
            $view_marker = $view_marker_arr['val'];
            $scope       = $view_marker_arr['uid'];

            $this->last_scope = $this->current_scope;
			$this->current_scope = $scope;
            return;
    	}
    	$this->reached_end = true;
    	return;
    }
    public function matchesSoFar()
    {
        // dd($this->blade_blocks->view_blocks);
    	//print_r($this->assumptions);
    	//echo "\n\n====================> MATCHING: \n";
    	//sleep(2);

        echo "MATCHING:\n";
        echo $this->left_side->implode('')."\n";
        echo $this->right_side->implode('')."\n";
        echo "\n";

    	if ($this->left_side->implode('') == $this->right_side->implode('')) {
            echo "MATCH\n";
    		return true;
    	}
        
        echo "NO MATCH:\n";
        echo $this->left_side->implode('')."\n";
        echo $this->right_side->implode('')."\n";
        echo "\n";
        
    	return false;
    }
    public function enteringNewBlock()
    {
        if ($this->current_scope != $this->last_scope) {
            if ($this->atFirstItemInBlock()) {
                return true;
            }
            
        }
        return false;
    }
    public function exitingBlock()
    {
        if ($this->current_scope != $this->last_scope) {
            if ($this->atLastItemInPreviousBlock()) {
                return true;
            }
            
        }
    }
    public function atFirstItemInBlock()
    {
        $generic_arr = explode('_', $this->current_scope);
        $generic = $generic_arr[0];
        $block = $this->blade_blocks->view_blocks[$generic];
        $blockstart = $block['start'];
        foreach ($this->view_markers as $loc => $view_marker) {
            $curr_marker = $this->generated_view_markers[$this->view_marker_head];
            $curr_loc = $curr_marker['loc'];
            if ($loc > $blockstart && $loc < $curr_loc) {
                // if its the first item, there will be nothing between these
                //dd("NOT AT FIRST ITEM", $loc, $this->view_marker_head);
                return false;
            }
        }
        return true;
    }
    public function atLastItemInPreviousBlock()
    {
        $generic_arr = explode('_', $this->last_scope);
        $generic = $generic_arr[0];
        $previousblock = $this->blade_blocks->view_blocks[$generic];

        $curr_marker = $this->generated_view_markers[$this->view_marker_head];
        $curr_loc = $curr_marker['loc'];

        if ($previousblock['end'] < $curr_loc) {
            return true;
        }
        return false;
    }
    public function leftSideIsAVariable()
    {
    	$current_left = $this->left_side->last();
    	return starts_with($current_left, '{{');
    }
    public function leftSideIsABlock()
    {
    	$current_left = $this->left_side->last();
    	return starts_with($current_left, '@');
    }
    public function setLeftSideWithAssumption($assumption) 
    {
        if ($assumption['val']) {
            $this->left_side[] = $assumption['val'];
        } else {
            //dd($assumption);
            //echo "NO VAL";
            $this->moveLeftSideForward();
        }
    }
    public function setLeftSideWithLoop($block, $blockid) 
    {
        $this->current_loop = $blockid;
        $this->view_marker_head = $block['start'];
        $this->moveLeftSideForward();
    }
    public function setLeftSideWithLoopEnd($blockid) 
    {
        $block = $this->blade_blocks->view_blocks[$blockid];
        $this->current_loop = null;
        $this->view_marker_head = $block['end'];
        $this->moveLeftSideForward();
        dd($this->left_side, $this->right_side, $this->current_loop);
    }
    public function blockIsChildOf($block, $id)
    {
        while ($block['parent']) {
            if ($block['parent'] == $id) {
                return true;
            }
            $block = $this->blade_blocks->view_blocks[$block['parent']];
        }
        return false;
    }
    public function getParentLoop($block)
    {
        //print_r($block);
        if (starts_with($block['parent'], '@foreach')) {
            return $block['parent'];
        }

        while (!starts_with($block['parent'], '@foreach')) {

            echo $block['parent'];

            if (isset($this->blade_blocks->view_blocks[$block['parent']])) {
                $block = $this->blade_blocks->view_blocks[$block['parent']];
                $parent = $block['parent'];
                return $parent;
            } else {
                //$this->current_loop = '';
                
                //$this->moveLeftSideForward();
                //dd($this->left_side, $this->current_scope, $this->current_loop);
                //continue;
                //dd($this->left_side, $this->right_side, $block, "NO PARENT");
                return null;
            }
        }
        
    }
    public function getRightSideStartingAt($html_loc, $num_tries)
    {
    	$sofar = 0;
    	$string = '';
    	foreach ($this->html_markers as $loc => $marker) {
    		if ($html_loc <= $loc) {
    			if ($sofar < $num_tries) {
    				$string .= $marker;
    				$sofar++;
    			} else {
    				continue;
    			}
    		}
    	}
    	return $string;
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

    public function generateAndTry() 
    {
        $this->assumptions->uids = [];

        $this->assumptions->ids = collect([]);
        $this->generated_view_markers = $this->assumptions->generateLeftSide();

        $this->current_scope = '@root_1';
        $this->last_scope = '@root_1';

        $this->left_side = collect([]);
        $this->right_side = collect([]);

        $this->view_marker_head = -1;
        $this->html_marker_head = -1;

        $this->variable_assumptions = collect([]);

        $iterations = 0;

        $this->reached_end = false;
        $onematch = false;

        while (!$this->reached_end) {

            // echo "===========================================> ITERATION: ".$iterations."\n";

            //$this->assumptions->setBooleanTrue('@if1_1');
            

            

            $iterations++;
            //print_r($this->left_side);
            if ($iterations > 100) {
                //dd($this->generated_view_markers);
                dd($this->left_side, $this->right_side, $this->variable_assumptions, "STUCK IN SOME SORT OF LOOP");
            }
            if (starts_with($this->current_scope, '@foreach')) {
                
                
            }
            /*
            echo "Current Scope: ".$this->current_scope."\n";
            echo "Previous Scope: ".$this->last_scope."\n";
            */
            if ($this->exitingBlock()) {
                // echo "\n\n====================> EXITING BLOCK\n";
                // echo $this->last_scope."\n";
                //dd($this->left_side, $this->right_side);
            }
            $entering_block = false;
            if ($this->enteringNewBlock()) {
                // echo "\n\n====================> ENTERING NEW BLOCK\n";
                // echo $this->current_scope."\n";
                //dd($this->left_side, $this->right_side);
                $entering_block = true;
            }



            if ($this->matchesSoFar()) {
                // echo "\n\n====================> MATCH\n";
                // echo "Moving both sides forward.\n";

                $this->current_variable = '';

                $this->moveBothSidesForward();
                //dd($this->left_side, $this->right_side);
                continue;
            }

        
            if ($this->current_variable) {
                // echo "\n\n====================> MATCHING VARIABLE\n";
                // echo $this->current_variable."\n";
                //dd($this->assumptions, $this->left_side, $this->right_side);
                $variable_assumption = $this->variable_assumptions->pop();
                //$this->html_marker_head = $variable_assumption['loc']['html'];
                $this->view_marker_head = $variable_assumption['loc']['view'];
                $variable_assumption['try'] += 1;
                $variable_assumption['val'] = $this->getRightSideStartingAt($variable_assumption['loc']['html'], $variable_assumption['try']);
                $this->variable_assumptions[] = $variable_assumption;
                //dd($variable_assumption);
                print_r($this->left_side);
                $tempvar = $this->left_side->pop();
                //$tempvar = '';
                if ($variable_assumption['try'] > 1) {
                    
                    // the first is blank, the rest have a value

                    $tempvar = $this->left_side->pop();
                    echo "POPPED $tempvar\n";

                    print_r($this->left_side);
                }
                
                
                echo "POPPED $tempvar\n";

                //dd($this->generated_view_markers, $this->left_side, $this->right_side, $variable_assumption);
                
                $this->setLeftSideWithAssumption($variable_assumption);
                
                $this->moveBothSidesForward();
                if ($variable_assumption['try'] > 4) {
                    dd($this->left_side, $this->right_side, $variable_assumption);
                }
                //sleep(1);
                //dd($this->generated_view_markers, $this->left_side, $this->right_side);
                continue;
            }

            if ($this->leftSideIsAVariable()) {

                //echo "\n\n====================> NEW VARIABLE\n";

                $this->current_variable = $this->left_side->last();
                //echo $this->current_variable."\n";

                $this->left_side->pop();

                $variable_assumption = [];
                $variable_assumption['var'] = $this->current_variable;
                $variable_assumption['scp'] = $this->current_scope;
                $variable_assumption['try'] = 0;
                $variable_assumption['val'] = '';
                $variable_assumption['loc'] = ['view' => $this->view_marker_head, 
                                      'html' => $this->html_marker_head];
                $this->variable_assumptions[] = $variable_assumption;
                $this->setLeftSideWithAssumption($variable_assumption);
                continue;
            }

            

            break;
        }
        
        

        print_r($this->variable_assumptions);
        $str = '';
        //dd($this->generated_view_markers);
        foreach ($this->generated_view_markers as $temparr) {
            $str .= $temparr['val'];
        }
        $try = ['distance'   => $this->view_marker_head,
                'similar'    => similar_text($this->html_markers->implode(''), $this->left_side->implode(''), $percent),
                'percent'    => $percent, 
                'curr_scope' => $this->current_scope,
                'last_scope' => $this->last_scope];

        if (count($this->variable_assumptions) > 0) {
            dd($this->html_markers, $this->generated_view_markers, $this->variable_assumptions, $try);
        }
        return $try;
        
    }
    public function removeLastAssumption()
    {
        $last_assumption = $this->assumptions->stack->pop();
        //echo "\nREMOVING ".$last_assumption['uid']."\n";
        return $last_assumption;
        //dd($this->assumptions->stack);
    }
    public function setBooleanTrue($uid)
    {
        $one_assumption = ['uid' => $uid, 'type' => 'boolean', 'val' => true];
        $this->assumptions->stack->push($one_assumption);
        //$one_assumption['trial'] = $this->addTrialInfo();
        //$this->assumptions->stack->pop();
        //$this->assumptions->stack->push($one_assumption);
        return $one_assumption;
    }
    public function setBooleanFalse($uid)
    {
        $one_assumption = ['uid' => $uid, 'type' => 'boolean', 'val' => false];
        $this->assumptions->stack->push($one_assumption);
        // $one_assumption['trial'] = $this->addTrialInfo();
        // $this->assumptions->stack->pop();
        // $this->assumptions->stack->push($one_assumption);
        return $one_assumption;        
    }
    public function setLoops($uid, $loopcount)
    {
        $one_assumption = ['uid' => $uid, 'type' => 'loop', 'val' => $loopcount];
        $this->assumptions->stack->push($one_assumption);
        //dd($this->assumptions->stack);
        // $one_assumption['trial'] = $this->addTrialInfo();
        // $this->assumptions->stack->pop();
        // $this->assumptions->stack->push($one_assumption);
        return $one_assumption;
    }
    public function addLoop($uid)
    {
        $one_assumption = ['uid' => $uid, 'type' => 'loop', 'val' => 'add'];
        $this->assumptions->stack->push($one_assumption);
        //$one_assumption['trial'] = $this->addTrialInfo();
        //$this->assumptions->stack->pop();
        //$this->assumptions->stack->push($one_assumption);
        return $one_assumption;
        
    }
    public function removeLoop($uid)
    {
        $one_assumption = ['uid' => $uid, 'type' => 'loop', 'val' => 'remove'];
        $this->assumptions->stack->push($one_assumption);
        // $one_assumption['trial'] = $this->addTrialInfo();
        // $this->assumptions->stack->pop();
        // $this->assumptions->stack->push($one_assumption);
        return $one_assumption;
        
    }
    public function addTrialInfo()
    {
        $this->generated_view_markers = $this->assumptions->generateLeftSide();
        $trial = $this->tryFromGenerated();
        return $trial;
    }


}



