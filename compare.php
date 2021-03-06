<?php
/*
 * Copyright (C) 2016 Pierre-Henry Favre <phf@atm-consulting.fr>
 *
 * This program and files/directory inner it is free software: you can
 * redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
 
 //require 'color.class.php';
require 'config.php';

$action = !empty($_POST['action']) ? $_POST['action'] : '';

switch ($action) 
{
	case 'getTDepot':
		echo json_encode(_getAllSubDirGited());
		break;
		
	case 'getTBranch':
		echo json_encode(_getTBranch($_POST['depot']));
		break;
		
	case 'execDiff':
		$THtml = '';
		$file = _createDiffFile($_POST['depot_path'], $_POST['branch_a'], $_POST['branch_b']);
		if (!empty($file)) _readDiffFile($THtml, $file);
		echo json_encode($THtml);
		break;
		
	case 'test':
		$THtml = '';
		_readDiffFile($THtml);
		echo json_encode($THtml);
		break;
		
	default:
		_displayHtmlCore();
		break;
}
exit;

function _displayHtmlCore()
{
	include './compare.html';
}

function _getTBranch($depot)
{
	$TBranch = array();
	exec('cd '.$depot.' && git branch ', $Tab);
	
	foreach ($Tab as &$branch_name)
	{
		$default = 0;
		if ($branch_name[0] == '*') $default = 1;
		$TBranch[] = array('branch_name' => trim($branch_name, ' *'), 'default' => $default);
	}

	return $TBranch;
}

function _createDiffFile($depot, $branch_a, $branch_b)
{
	$dir = dirname(__FILE__).'/tmp';
	$target = $dir.'/compare.diff';
	
	if (!is_writable($dir))
	{
		header($_SERVER["SERVER_PROTOCOL"].' 500 Internal Server Error. Can\'t write data');
		exit('<strong>Erreur :</strong> impossible d\'écrire dans le dossier : '.$dir);
	}
	else 
	{
		if (empty($depot)) $cmd = 'cd '.$dir.' && git diff '.$branch_a.'..'.$branch_b.' > '.$target;
		else $cmd = 'cd '.$depot.' && git diff '.$branch_a.'..'.$branch_b.' > '.$target;
		
		exec($cmd);
		return $target;
	}
}

function _readDiffFile(&$THtml, $srcFile = '/var/www/html/test.diff')
{
	$handle = fopen($srcFile, 'r');
	
	if ($handle)
	{
		$last_new_file = '';
		$TData = $TLine = array();
		
		while ($line = fgets($handle))
		{
			if (substr($line, 0, 4) == 'diff')
			{
				$last_new_file = $line;
				$TData[$last_new_file] = array();
			}
			else {
				$TData[$last_new_file][] = $line;
			}
		}
		
		fclose($handle);
		
		$t1 = microtime(true);
		foreach ($TData as $diff_file => &$Tab)
		{
			$TLine[] = _getModificationOfFile($Tab, $diff_file);
		}
		
		_printTLine($THtml, $TLine);
	}
}

function _getModificationOfFile(&$Tab, $diff_file)
{
	$TRes = array();
	$title = _cleanTitle($diff_file);

	foreach ($Tab as $i => &$line)
	{
		$substr = substr($line, 0, 3);
		if ($substr == 'del')
		{
			$indice_line_modified = htmlentities($line);
			$TRes[$title][$indice_line_modified][0]['file_deleted'] = $indice_line_modified;
			break;
		}
		elseif ($substr == 'new')
		{
			$indice_line_modified = htmlentities($line);
			$TRes[$title][$indice_line_modified][0]['file_added'] = $indice_line_modified;
			break;
		}
		
		if ($i <= 2) continue; // ignore
		if (($line[0] == '-' && $line[1] == '-' && $line[2] == '-') || ($line[0] == '+' && $line[1] == '+' && $line[2] == '+')) continue;
		
		if ($line[0] == '@' && $line[1] == '@')
		{
			$index = 0;
			$index_start_delete = null;
			
			$indice_line_modified = rtrim($line,"\r\n");
			$indice_line_modified = htmlentities($indice_line_modified);
			$TRes[$title][$indice_line_modified] = array();
			
			preg_match_all('/(\+|-)[0-9]*/', $line, $TMatch); // $TMatch[0] = array(-47, +47)
			
			$line_number_a = abs($TMatch[0][0]);
			$line_number_b = abs($TMatch[0][1]);
		}
		else 
		{
			//TODO si le contenu de la ligne est trop long, la largeur des colonnes n'est pas respecté à l'affichage
			$line = htmlentities($line);
			
			if (!empty($line[0]) && $line[0] == '-') // Ligne supprimée sur branch A
			{
				$line = substr($line, 1);
				if (is_null($index_start_delete)) $index_start_delete = $index;
				$TRes[$title][$indice_line_modified][$index] = array('line_number_a' => $line_number_a, 'line_number_b' => '', 'a' => $line, 'b' => '', 'line_deleted' => true);
				$line_number_a++;
				$index++;
			}
			elseif (!empty($line[0]) && $line[0] == '+') // Ligne ajoutée sur branch B
			{
				$line = substr($line, 1);
				if (is_null($index_start_delete)) $index_start_delete = $index;
				if (!isset($TRes[$title][$indice_line_modified][$index_start_delete]['a']))
				{
					$TRes[$title][$indice_line_modified][$index_start_delete]['line_number_a'] = '';
					$TRes[$title][$indice_line_modified][$index_start_delete]['a'] = '';
				}

				$TRes[$title][$indice_line_modified][$index_start_delete]['line_number_b'] = $line_number_b;
				$TRes[$title][$indice_line_modified][$index_start_delete]['b'] = $line;
				$TRes[$title][$indice_line_modified][$index_start_delete]['line_added'] = true;
				
				$line_number_b++;
				$index_start_delete++;
				
				if ($index <= $index_start_delete) $index++;
			}
			else // Ligne commune
			{
				$index_start_delete = null;
				$TRes[$title][$indice_line_modified][$index] = array('line_number_a' => $line_number_a, 'line_number_b' => $line_number_b, 'a' => $line, 'b' => $line);
				$line_number_a++;
				$line_number_b++;
				$index++;
			}
			
		}
	}
	
	return $TRes;
}

// Ce base sur un diff avec --word-diff
function _getModificationOfFile2(&$Tab, $diff_file)
{
	$TRes = array();
	$title = _cleanTitle($diff_file);

	foreach ($Tab as $i => &$line)
	{
		if ($i <= 2) continue; // ignore
		if (($line[0] == '-' && $line[1] == '-' && $line[2] == '-') || ($line[0] == '+' && $line[1] == '+' && $line[2] == '+')) continue;
		
		if ($line[0] == '@' && $line[1] == '@')
		{
			$index = 0;
			$index_start_delete = null;
			
			$indice_line_modified = htmlentities($line);
			$TRes[$title][$indice_line_modified] = array();
			
			preg_match_all('/(\+|-)[0-9]*/', $line, $TMatch); // $TMatch[0] = array(-47, +47)
			
			$line_number_a = abs($TMatch[0][0]);
			$line_number_b = abs($TMatch[0][1]);
		}
		else 
		{
			$deleted = $added = false;
			
			$posStrDeleted = strpos($line, '[-');
			$posStrAdded = strpos($line, '{+');
			
			$line_a = $line;
			$line_b = $line;

			if ($posStrAdded !== false)
			{
				$added = true;
				$posStrAdded2 = strpos($line, '+}') + 2 - $posStrAdded;
				$line_a = str_replace(substr($line, $posStrAdded, $posStrAdded2), '', $line);
		
			}
			if ($posStrDeleted !== false)
			{
				$deleted =true;
				$posStrDeleted2 = strpos($line, '-]') + 2 - $posStrDeleted;
				$line_b = str_replace(substr($line, $posStrDeleted, $posStrDeleted2), '', $line);
			}
			
			$line_a = htmlentities($line_a);
			$line_a = str_replace(array('[-', '-]'), array('<span class="part_deleted">', '</span>'), $line_a);
			
			$line_b = htmlentities($line_b);
			$line_b = str_replace(array('{+', '+}'), array('<span class="part_added">', '</span>'), $line_b);

			$TRes[$title][$indice_line_modified][] = array(
				'line_number_a' => $line_number_a
				,'line_number_b' => $line_number_b
				,'a' => $line_a
				,'b' => $line_b
				,'line_deleted' => $deleted
				,'line_added' => $added
			);
			
			$index++;
			$line_number_a++;
			$line_number_b++;
		}
	}
	
	return $TRes;
}

function _cleanTitle($diff_file)
{
	$diff_file = trim(substr($diff_file, 10));
	$diff_file = explode(' ', $diff_file);
	$diff_file = substr($diff_file[0], 1);
	
	return htmlentities($diff_file);
}

function _printTLine(&$THtml, &$TLine)
{
	foreach ($TLine as $k => &$Tab)
	{
		$str = '<div class="diff_file">';
			
		foreach ($Tab as $title => &$T) 
		{
			$str .= '<div class="diff_title">'.$title.'</div>';
			
			$nb_sub_title = count($T);
			$i=0;
			foreach ($T as $sub_title => &$TBranch)
			{
				$i++;
				$str .= '<div class="diff_data">'; 
				
				$str .= '<table>';
				$str .= '<thead></thead>';
				$str .= '<tbody><tr class="diff_sub_title"><td colspan="1" class="line_number">&nbsp;</td><td colspan="3">'.$sub_title.'</td></tr>';
				
				if (!empty($TBranch))
				{
					foreach ($TBranch as &$TVal)
					{
						if (!empty($TVal['file_deleted']) || !empty($TVal['file_added'])) break;
						
						$class_line_number_a = '';
						$class_td_a = 'branch_a';
						$class_line_number_b = '';
						$class_td_b = 'branch_b';
						
						$sign_a = '';
						$sign_b = '';
						
						if (!empty($TVal['line_deleted'])) { $sign_a = '-'; $class_td_a .= ' line_deleted'; $class_line_number_a = ' line_num_deleted'; }
						if (!empty($TVal['line_added'])) { $sign_b = '+'; $class_td_b .= ' line_added';  	$class_line_number_b = ' line_num_added'; }
						
						if (empty($TVal['a'])) { $class_line_number_a .= ' empty_cell'; $class_td_a .= ' empty_cell'; }
						if (empty($TVal['b'])) { $class_line_number_b .= ' empty_cell'; $class_td_b .= ' empty_cell'; }
						
						$str .= '<tr>';
						$str .= '<td class="line_number'.$class_line_number_a.'" data-line-number="'.$TVal['line_number_a'].'" ></td><td class="'.$class_td_a.'"><span class="sign" data-line-sign="'.$sign_a.'"></span><span class="code_line">'.$TVal['a'].'</span></td>';
						$str .= '<td class="line_number separate_line'.$class_line_number_b.'" data-line-number="'.$TVal['line_number_b'].'" ></td><td class="'.$class_td_b.'"><span class="sign" data-line-sign="'.$sign_b.'"></span><span class="code_line">'.$TVal['b'].'</span></td>';
						$str .= '</tr>';
					}

				}
				
				if ($nb_sub_title == $i) $str .= '<tr class="diff_sub_title_end"><td colspan="1" class="line_number">&nbsp;</td><td colspan="3">&nbsp;</td></tr>';
				$str .= '</tbody><tfooter></tfooter></table>';
				$str .= '</div>';
			}
		}

		$str .= '</div>';
		$THtml[] = $str;
	}
	
	unset($str, $TLine);
}

function _getAllSubDirGited($recursive=0, $deep=0, $TDir='')
{
	if ($deep >= RECURSIVE_DEEP) return array();
	if (empty($TDir))
	{
		$recursive = RECURSIVE_LOOP;
		$TDir = unserialize(TAB_GIT_DIR_LOCATE);
	}
	
	$TGitDir = array();
	
	foreach ($TDir as $dir)
	{
		$TSubDir = scandir($dir);
		
		foreach ($TSubDir as $sub_dir)
		{
			$fullpath = $dir.'/'.$sub_dir;
			if (substr($sub_dir, -4) == '.git')
			{
				$TGitDir[$sub_dir] = $fullpath;
			}
			if (!is_dir($fullpath) || $sub_dir == '.' || $sub_dir == '..' || $sub_dir == '.git') continue;
			if (is_dir($fullpath) && is_dir($fullpath.'/.git'))
			{
				$TGitDir[$sub_dir] = $fullpath;
			}
			
			if ($recursive)
			{
				$TGitDir += _getAllSubDirGited(1, ++$deep, array($fullpath));
			}
		}	
	}
	
	return $TGitDir;
}
