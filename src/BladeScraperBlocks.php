<?php namespace TonsOfLaz\BladeScraper;

class BladeScraperBlocks {

    protected $view;
	public $view_blocks;
	
	protected $ifcount;
	protected $forcount;

	//public $view_block_hierarchy;
	public $variable_parents;

	public function getCurrentScope($position)
	{
		// initialize variable to higher than anything document will be
		$current_scope = '';
		foreach ($this->view_blocks as $id => $block) {
			$start = $block['start'];
			$end   = $block['end'];
			$within_block = ($position >= $start && $position <= $end);

			if ($within_block) {
				$current_scope = $id;
			}
		}
        echo "\nPOS: $position, SCOPE: $current_scope\n";
		return $current_scope;
	}

	public function __construct($view)
    {
        $this->view = $view;
    	$this->ifcount = 0;
        $this->forcount = 0;

        $structures = [];
        $rawstructure = preg_split('/(\@root|\@endroot|\@if|\@endif|\@foreach|\@endforeach)/', $view, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_OFFSET_CAPTURE|PREG_SPLIT_NO_EMPTY);
        //dd($rawstructure);

        foreach ($rawstructure as $loopkey => $rawsection) {
            $sectionname = $rawsection[0];
            if ($sectionname == '@if' || $sectionname == '@foreach' || $sectionname == '@root') {

                $endcount = 1;
                for ($i = $loopkey + 1; $i < count($rawstructure); $i++) {
                    $tempsection = $rawstructure[$i];
                    if ($tempsection[0] == $sectionname) {
                        $endcount++;
                    }

                    if ($tempsection[0] == '@end'.str_replace('@', '', $sectionname)) {
                        //echo $endcount;
                        if ($endcount == 1) {
                            // this is the correct ending for this tag
                            $struct = [];
                            $struct['type'] = $sectionname;
                            $struct['start'] = $rawsection[1];
                            $struct['end'] = $tempsection[1];
                            $length = $tempsection[1] - ($rawsection[1] + strlen($sectionname));
                            $rawcontent = substr($view, $rawsection[1] + strlen($sectionname), $length);
                            $content = "";

                            $forarr = [];
                            if ($sectionname == '@foreach') {
                                preg_match('/\(\$(.*?) as \$(.*?)\)/', $rawcontent, $vars);
                                $forname = $vars[1];
                                $forsingular = $vars[2];
                                $plural_split = explode('->', $forname);
                                if (isset($plural_split[1])) {
                                    $parent = $plural_split[0];
                                    $plural = $plural_split[1];
                                    $forarr['parent'] = $parent;
                                    $forarr['plural'] = $plural;
                                    $this->variable_parents[$plural] = $parent;
                                    $this->variable_parents[$forsingular] = $plural;
                                } else {
                                    $forarr['plural'] = $plural_split[0];
                                    $this->variable_parents[$forsingular] = $forname;
                                }
                                
                                $forarr['singular'] = $forsingular;
                                $temp_arr = explode($vars[0], $rawcontent);
                                $content = $temp_arr[1];
                                $forarr['content'] = $content;
                                $struct['command'] = $forarr;
                                
                            }
                            $ifarr = [];
                            if ($sectionname == '@if') {
                                $boolean_var = preg_match('/\(\s?\$(.*?)\s?\)/', $rawcontent, $vars);
                                $boolean_name = $vars[1];
                                $var_split = explode('->', $boolean_name);
                                if (isset($var_split[1])) {
                                    $ifarr['parent'] = $var_split[0];
                                    $ifarr['boolean'] = $var_split[1];
                                    $this->variable_parents[$var_split[1]] = $var_split[0];
                                } else {
                                    $ifarr['boolean'] = $var_split[0];
                                }
                                $boolean_arr = explode($vars[0], $rawcontent);
                                $content = $boolean_arr[1];
                                $ifarr['content'] = $boolean_arr[1];
                                //dd($vars);
                                $struct['command'] = $ifarr;

                                
                                
                            }
                            if ($sectionname == '@root') {
                                
                                $rootarr['content'] = $rawcontent;
                                //dd($vars);
                                $struct['command'] = $rootarr;

                                
                                
                            }
                            //dd($content);
                            
                            //$struct['view_markers'] = preg_split()
                            //$struct['rawcontent'] = $rawcontent;
                            $struct['structures'] = [];

                            $structures = $this->addToStructures($struct, $structures, '');
                            
                            $i = count($rawstructure);
                        }
                        $endcount--;
                    }
                }
            }
        }
        
        // clear out unused 'structures' array needed for recursion
        foreach ($this->view_blocks as $skey => $view_block) {
        	unset($view_block['structures']);
        	$this->view_blocks[$skey] = $view_block;
        }
        //dd($this->view_blocks);

        $this->view_block_hierarchy = $this->getViewStructure($structures);
        $this->addRegexes();
        //dd($this->view_block_hierarchy);
    }
    public function addRegexes()
    {
        foreach ($this->view_blocks as $id => $block) {
            //dd($this->view);
            $content = $block['command']['content'];
            //dd($content);
            //dd($content);
            $start = strpos($this->view, $content);
            $end   = $block['end'];
            //dd($start);
            $blockstart = $start;
            $regex = "";
            $generic_regex = '';
            foreach ($block['children'] as $childid => $pos) {
                $childstart = $pos['start'];
                $childend =   $pos['end'] + strlen('end'.$this->view_blocks[$childid]['type']); 
                $tempstr = substr($this->view, $blockstart, $childstart - $blockstart);
                $tempstr = preg_replace('/{{\s\$(.*?)\-\>(.*?)\s}}/', 'LAZ$1ZAL$2LAZ', $tempstr);
                $tempstr = preg_replace('/{{\s\$(.*?)\s}}/', 'LAZ$1LAZ', $tempstr);

                $genericstr = substr($this->view, $blockstart, $childstart - $blockstart);
                $genericstr = preg_replace('/{{.*?}}/', 'LAZ', $genericstr);
                //dd($tempstr);
            
                $regex .= preg_quote($tempstr);
                $generic_regex .= preg_quote($genericstr);

                $regex = preg_replace('/LAZ(.*?)ZAL(.*?)LAZ/', '(?P<$1ARROW$2>.*?)', $regex);
                $regex = preg_replace('/LAZ(.*?)LAZ/', '(?P<$1>.*?)', $regex);

                $regex .= '[[[ '.$childid.' ]]]'; 
                $generic_regex .= 'LAZ';
                //$regex .= '.*?';
                $blockstart = $childend;
            }
            //dd($childend);
            //dd($blockstart, $end - $blockstart + 5);

            $tempstr = substr($this->view, $blockstart, $end - $blockstart);

            $genericstr = substr($this->view, $blockstart, $end - $blockstart);
            $genericstr = preg_replace('/{{.*?}}/', 'LAZ', $genericstr);
            
            $tempstr = preg_replace('/{{\s\$(.*?)\-\>(.*?)\s}}/', 'LAZ$1ZAL$2LAZ', $tempstr);
            $tempstr = preg_replace('/{{\s\$(.*?)\s}}/', 'LAZ$1LAZ', $tempstr);
            //dd($tempstr);
        
            $regex .= preg_quote($tempstr);
            $generic_regex .= preg_quote($genericstr);

            $generic_regex = preg_replace('/LAZ/', '.*?', $generic_regex);

            $regex = preg_replace('/LAZ(.*?)ZAL(.*?)LAZ/', '(?P<$1ARROW$2>.*?)', $regex);
            $regex = preg_replace('/LAZ(.*?)LAZ/', '(?P<$1>.*?)', $regex);
    
            //dd($id);
            if ($id == '@root') {
                $regex = '(?P<ignore_beginning>.*?)'.$regex.'(?P<ignore_end>.*?)';
            }

            if (starts_with($id, '@if')) {
                // Using the blade views may add a space as part of the @if block
                // So we account for it here
                $boolvarname = '';
                if (isset($block['command']['parent'])) {
                    $boolvarname .= $block['command']['parent'].'ARROW';
                }
                if (isset($block['command']['boolean'])) {
                    $boolvarname .= $block['command']['boolean'];
                }
                $generic_regex = '(?P<'.$boolvarname.'>\s*'.ltrim($generic_regex).')';
                $regex = '(?P<'.$boolvarname.'>\s*'.ltrim($regex).')';

                //dd($regex);
            }

            $block['generic_regex'] = $generic_regex;
            $block['regex'] = $regex;
            //dd($block);
            $this->view_blocks[$id] = $block;
        }

        //dd($this->view_blocks);

    }
    public function addToStructures($struct, $structures, $parent) {
        
        
        $newstruct = true;
        foreach ($structures as $tempkey => $tempstruct) {

            $tempstart = $tempstruct['start'];
            $tempend = $tempstruct['end'];

            if ($struct['start'] > $tempstart && $struct['end'] < $tempend) {
                // it is inside this struct

            	
                
                $structures[$tempkey]['structures'] = $this->addToStructures($struct, $structures[$tempkey]['structures'], $tempkey);
                $newstruct = false;

                foreach ($structures[$tempkey]['structures'] as $childkey => $childstruct) {
                    //dd($structures[$tempkey]['structures'], $childkey);
                    $this->view_blocks[$tempkey]['children'][$childkey]['start'] = $childstruct['start'];
                    $this->view_blocks[$tempkey]['children'][$childkey]['end']   = $childstruct['end'];


                }
                //dd($structures);
                //$this->view_blocks[$tempkey] = $struct;
                continue;
            }

            
        }
        if ($newstruct) {
            $type = $struct['type'];
            if ($type == '@foreach') {
                $this->forcount++;
                $uniqueid = $type.$this->forcount;
            }
            if ($type == '@if') {
                $this->ifcount++;
                $uniqueid = $type.$this->ifcount;
            }
            if ($type == '@root') {
                $uniqueid = $type;
            }
            
            $struct['children'] = [];
            $struct['parent'] = $parent;
            $structures[$uniqueid] = $struct;

        
            $this->view_blocks[$uniqueid] = $struct;

        }
        return $structures;
    }

    public function getViewStructure($structures)
    {
        foreach ($structures as $skey => $structure) {
            if (count($structure['structures']) > 0) {
                $structure['structures'] = $this->getViewStructure($structure['structures']);
                
            } 
            $structures[$skey] = $structure['structures'];
            
        }

        return $structures;
    }

}