<?
/*
WebFM - Web File Manager 0.1 
Copyright (C) 2005-2006, Chris Chabot

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

*//*

    made with tab-size: 4

    To configure this file manager for all virtual hosts
	and hide the script from the virual host's document root
	place fm.php in a dir outside of the document root
	For example /var/www/fm.php, and the images in /var/www/fmicons
	then configure Apache aliases to access them as such:
	Alias /filemanager "/var/www/fm.php"
	Alias /fmicons/ "/var/www/fmicons/"
	then you can use the file manager on any virtual host thru
	http://localhost/filemanager
*/

$conf_images    = '/fmicons'; // Directory that contains the FileManager images
$conf_showdirs  = TRUE; // Show directories in the file list?
$conf_chroot    = $_SERVER['DOCUMENT_ROOT']; // Set this manualy to '' or any other given path to alow browsing outside of the webroot
$conf_sort_dirs = TRUE; // Sort the directories by name (otherwise displayed in order of file system)
$conf_resolveid = TRUE; // Resolve UID / GID names?
$conf_passwddir = '';   // Overrule passwd file location

// ini settings to allow long execution time, larger memory usage and large file uploads
ini_set("max_execution_time",60);
ini_set("upload_max_filesize","32M");
ini_set("memory_limit","16M");
ini_set("output_buffering",1);
ini_set("magic_quotes_gpc",0);

// Force no cache for this script so directories are reloaded correctly
header("Pragma: no-cache");
header("Cache-Control: no-store");


function change_passwd()
{
	global $conf_images, $passwd_key, $passwd, $passwd_file;
	if (isset($_POST['newpass'])) {
		$passwd[$passwd_key] = crypt($_POST['newpass'],'$fm');
		$output = "<?\n\$passwd = array(\n";
		reset($passwd);
		$cnt = 1;
		while (list($key,$val) = each($passwd)) {
			$output .= "'$key' => '$val'";
			if ($cnt < count($passwd)) {
				$output .= ",";
			}
			$output .= "\n";
			$cnt++;
		}
		$output .= ");\n?>";
		$fp = fopen($passwd_file,'w+');
		fwrite($fp,$output);
		fclose($fp);
		echo "<script>this.opener.location=this.opener.location; window.close();</script>";
		return;
	} else {
		echo "<html><head><LINK href=\"$conf_images/fm.css\" rel=stylesheet></head><body>\n".
			 "<form action=\"{$_SERVER['PHP_SELF']}?event=passwd\" method=\"post\">".
	 		 "<div class=uploadheader><br>Change Password<br><br>".
	 		 "<input type=\"text\" name=\"newpass\" size=32><br><br>".
	 		 "<input type=\"submit\" value=\"Save\"> <input type=button value=\"Cancel\" onClick=\"window.close();\"><br>".
	 	 	"</div></form></body></html>";
	}
}


function authenticate_header()
{
	Header("WWW-authenticate: basic realm=\"{$_SERVER['SERVER_NAME']} (Please use FM as username)\"");
	Header("HTTP/1.0 401 Unauthorized");
	die("<h1>Login required</h1><h2>All unauthorised access is denied.</h2>");
}
				

function authenticate_get_passwd()
{
	global $passwd_file, $passwd_key, $conf_passwddir;
	// find passwd file location. Prefered is outside of the document root
	// so the encrypted passwords cant be downloaded by the file manager
	// and brute-force cracked. This only works if fm.php is located outside of 
	// the document-root and uses an Apache Alias to call it. Also the web 
	// process owner should have write permission to the directory, or create 
	// the file and make the web-user the owner of it
	// (ie: touch fm_passwd.php && chown apache.apache fm_passwd.php)
	// Fallbacks are document_root, and current directory
	$passwd_file = FALSE;
	if ($conf_passwddir && $conf_passwddir != '') {
		$passwd_file = $conf_passwddir."/fm_passwd.php";
		if (!authenticate_check_file($passwd_file)) $passwd_file = false;
	}
	if (!$passwd_file && isset($_SERVER['PATH_TRANSLATED'])) {
		$passwd_file = dirname($_SERVER['PATH_TRANSLATED'])."/fm_passwd.php";
		if (!authenticate_check_file($passwd_file)) $passwd_file = false;
	} 
	if (!$passwd_file && isset($_SERVER['DOCUMENT_ROOT'])) {
		$passwd_file = $_SERVER['DOCUMENT_ROOT']."/fm_passwd.php";
		if (!authenticate_check_file($passwd_file)) $passwd_file = false;
	}
	if (!$passwd_file) {
		$passwd_file = "fm_passwd.php";
		if (!authenticate_check_file($passwd_file)) {
			die("Could not create passwd file(s), aborting");
		}
	}
	$passwd_key = $_SERVER['SERVER_NAME'];
}


function authenticate_check_file($file)
{
	if (!file_exists($file)) {
		if (!@touch($file)) {
			return false;
		} 
	}
	return true;
}


function authenticate()
{
	global $passwd_file, $passwd_key, $passwd;
	authenticate_get_passwd();
	include_once($passwd_file);
	if (isset($_SERVER['PHP_AUTH_PW'])) {
		if (isset($passwd[$passwd_key])) {
			if ($passwd[$passwd_key] == crypt($_SERVER['PHP_AUTH_PW'],'$fm')) {
				return true;
			}
		} else {
			// allow user in, but trigger missing password alert
			return false;
		}
	}
	authenticate_header();
}


function download_file()
{
	global $conf_chroot;
	if (!isset($_GET['file']) || $_GET['file'] == '') {
		return;
	}
	$filename = basename($_GET['file']);
	$file     = $conf_chroot.format_path(dirname($_GET['file'])).$filename;
	if (!file_exists($file)) {
		die("No such file or directory");
	}
	$size     = filesize($file);
	header("Content-Type: application/save");
	header("Content-Length: $size");
	header("Content-Disposition: attachment; filename=\"$filename\"");
	header("Content-Transfer-Encoding: binary");
	if ($fp = fopen("$file", "rb")) {
		fpassthru($fp);
	}
	fclose($fp);
}


function recurse_paste_or_delete($file,$dest_dir,$action)
{
	global $conf_chroot;
	$dest = $conf_chroot.$dest_dir.basename($file);
	if (is_dir($file)) {
		$dir = substr($file,strrpos($file,'/')+1);
		if ($action == 'cut' || $action == 'copy') {
			if (($mod = fileperms($file)) === FALSE) {
				// if we cant read old dir mod, assume a safe default
				$mod = '0755';
			}
			mkdir($conf_chroot.$dest_dir.$dir,$mod);
		}
		$dh = @opendir($file);
		while (($name = readdir($dh)) !== FALSE) {
			if ($name != '.' && $name != '..') {
				recurse_paste_or_delete($file.'/'.$name,$dest_dir.$dir.'/',$action);
			}
		}
		@closedir($dh);
		if ($action == 'delete' || $action == 'cut') {
			rmdir($file);
		}
	} else switch ($action) {
		case 'cut':
			// Prior to PHP 4.3.3, rename() could not rename files across
			// partitions on *nix based systems
			$mod = fileperms($file);
			if (version_compare(phpversion(),'4.3.3') == -1) {
				if (copy($file,$dest)) {
					unlink($file);
				}
			} else {
				rename($file,$dest);
			}
			// Set dest file mods same as original
			if ($mod) {
				chmod($dest,$mod);
			}
			break;
		case 'copy':
			$mod = fileperms($file);
			copy($file,$dest);
			// Set dest file mods same as original
			if ($mod) {
				chmod($dest,$mod);
			}
			break;
		case 'delete':
			unlink($file);
			break;
	}
}

function paste_or_delete($action)
{
	global $current_path, $conf_chroot;
	reset($_SESSION['selected']);
	while (list(,$file) = each($_SESSION['selected'])) {
		$real_file = $conf_chroot.$file;
		if (file_exists($real_file)) {
			recurse_paste_or_delete($real_file,$current_path,$action);
		}
	}
	// w/o clearing stat cache, php would show deleted/moved files in readdir()
	clearstatcache();
}

function make_directory()
{
	global $conf_chroot, $conf_images, $current_path;
	if (!is_dir($conf_chroot.$current_path)) {
		// Quick check to see if path is a valid location.
		// Path it self is already filtered in main with format_path()
		die("Invalid path specified");
	}
	if (isset($_POST['dir']) && $_POST['dir'] != '') {
		$dir = format_path($conf_chroot.$current_path.$_POST['dir']);
		if (!mkdir($dir,0755)) {
			echo "<script>alert('Error making $dir');</script>\n";
		}
		echo "<script>this.opener.location=this.opener.location; window.close();</script>";
	} else {
		echo "<html><head><LINK href=\"$conf_images/fm.css\" rel=stylesheet></head><body>\n".
			 "<form action=\"{$_SERVER['PHP_SELF']}?event=mkdir&path=$current_path\" method=\"post\">".
		 	"<div class=uploadheader><br>Create Directory<br><br>".
		 	"<input type=\"text\" name=\"dir\" size=32><br><br>".
		 	"<input type=\"submit\" value=\"Create\"> <input type=button value=\"Cancel\" onClick=\"window.close();\"><br>".
		 	"</div></form></body></html>";
	}
}


function upload_file()
{
	global $conf_images, $conf_chroot, $current_path;
	if (!is_dir($conf_chroot.$current_path)) {
		// Quick check to see if path is a valid location.
		// Path it self is already filtered in main with format_path()
		die("Invalid path specified");
	}
	if (count($_FILES) == 0) {
		echo "<html><head><LINK href=\"$conf_images/fm.css\" rel=stylesheet></head><body>\n".
			 "<form action=\"{$_SERVER['PHP_SELF']}?event=upload&path=$current_path\" method=\"post\" ENCTYPE=\"multipart/form-data\">".
			 "<div class=uploadheader><br>Select File to Upload<br><br>".
			 "<input type=\"file\" name=\"userfile\" size=32><br><br>".
			 "<input type=\"submit\" value=\"Upload\"> <input type=button value=\"Cancel\" onClick=\"window.close();\"><br>".
			 "</div></form></body></html>";
	} else {
		if ($_FILES['userfile']['error'] !== 0) {
			switch ($_FILES['userfile']['error']) {
				case UPLOAD_ERR_INI_SIZE:
				case UPLOAD_ERR_FORM_SIZE:
					$reason = "File size exceeds max allowed";
				break;
				case UPLOAD_ERR_PARTIAL:
					$reason = "File only partialy uploaded";
					break;
				case UPLOAD_ERR_NO_FILE:
					$reason = "No file specified";
					break;
				default:
					$reason = "Unhandled error";
					break;
			}
			echo "<html><body><h1>Error uploading file: $reason</h1></body></html>";
		}
		$dest = $conf_chroot.$current_path.basename($_FILES['userfile']['name']);
		// move_uploaded_file prevents upload file injection attacks, etc so
		// doesn't need to be re-checked in userspace
		if (move_uploaded_file($_FILES['userfile']['tmp_name'], $dest)) {
			echo "<script>this.opener.location=this.opener.location; window.close();</script>";
		} else {
			echo "<html><body><h1>Error uploading file: Possible file upload attack!</h1></body></html>";
		}
	}
}


function draw_files()
{
	global $current_path, $conf_chroot, $conf_sort_dirs, $conf_showdirs, $conf_images,
		   $total_files, $total_size, $total_dirs;
	$path        = $conf_chroot."/".$current_path;
	$total_files = 0;
	$total_size  = 0;
	echo "<td width=100% height=100% align=left valign=top>\n".
		 "<div style=\"width: 100%; height: 100%; overflow: auto;\">\n".
		 "<table cellspacing=0 cellpadding=0 height=100% width=100%>\n".
		 "<form name=\"selectform\" method=\"post\" action=\"{$_SERVER['PHP_SELF']}?path=$current_path\">\n".
		 "<input type=\"hidden\" name=\"action\" value=\"\">\n".
		 "<tr>\n".
		 "<td class=fileheader><b>File Name</b></td>\n".
		 "<td class=fileheader width=80><b>Size</b></td>\n".
		 "<td class=fileheader width=120><b>Date / Time</b></td>\n".
		 "<td class=fileheader width=80><b>Owner</b></td>\n".
		 "<td class=fileheader width=80><b>Group</b></td>\n".
		 "<td class=fileheader width=80><b>Permission</b></td>\n".
		 "<td class=fileheader width=20>&nbsp;</td>\n".
		 "<td class=fileheader width=20>&nbsp;</td>\n".
		 "</tr>\n";
	if (!$dh   = opendir($path)) {
		die("Fatal error opening $path");
	}
	$files = array();
	$dirs  = array();
	while (($name = readdir($dh)) !== FALSE) {
		$stat          = stat("$path/$name");
		$file['name']  = $name;
		$file['size']  = format_size($stat['size']);
		$file['date']  = date("d/m/Y H:i",$stat['mtime']);
		$file['owner'] = resolve_user($stat['uid']);
		$file['group'] = resolve_group($stat['gid']);
		$file['perm']  = resolve_perms(fileperms("$path/$name"));
		if (is_link("$path/$name")) {
			$file['link'] = "<font color=blue><i> --> ".readlink("$path/$name")."</i></font>";
		} else {
			$file['link'] = '';
		}
		if (is_dir("$path/$name")) {
			$total_dirs++;
			if ($conf_showdirs) {
				$dirs[$name]  = $file;
			}
		} else {
			$files[$name] = $file;
			$total_files++;
			$total_size += $stat['size'];
		}
	}
	closedir($dh);
	reset($files);
	reset($dirs);
	if ($conf_sort_dirs) {
		ksort($files);
		ksort($dirs);
	}
	while (list(,$file) = each($dirs)) {
		if (($file['name'] != '.') && 
			($current_path != '/' || $file['name'] != '..')) {
			if ($file['name'] == '..') {
				$tmp = substr($current_path,0,strlen($current_path)-1);
				$tmp = substr($tmp,0,strrpos($tmp,'/'));
				if ($tmp != '') {
					$link = $_SERVER['PHP_SELF']."?path=$tmp";
				} else {
					$link = $_SERVER['PHP_SELF'];
				}
			} else {
				$link = $_SERVER['PHP_SELF']."?path=$current_path{$file['name']}";
			}
			if ($file['name'] == '..') {
				$icon = 'folder-up.gif';
			} else {
				$icon = 'folder.gif';
			}
			echo "<tr>\n".
			 	 "<td class=fileentry><a class=\"filelink\" href=\"$link\"><img src=\"$conf_images/$icon\" width=16 height=16 align=left><b>{$file['name']}</b>{$file['link']}</a></td>\n".
				 "<td class=fileentry>&nbsp;</td>\n".
			 	 "<td class=fileentry nowrap>{$file['date']}</td>\n".
			 	 "<td class=fileentry nowrap>{$file['owner']}</td>\n".
			 	 "<td class=fileentry nowrap>{$file['group']}</td>\n".
			 	 "<td class=fileentry nowrap>{$file['perm']}</td>\n".
			 	 "<td class=fileentry>&nbsp;</td>\n";
			if ($file['name'] == '..') {
				echo "<td class=fileentry>&nbsp;</td>\n";
			} else {
				echo "<td class=fileentry><input type=checkbox name=selected[] value=\"$current_path{$file['name']}\"></td>\n";
			}
			echo "</tr>\n";
		}
	}
	$icons = array(
		'text'    => array('txt'),
		'word'    => array('doc','rtf'),
		'excel'   => array('xls'),
		'media'   => array('mp3','mpg','mpeg','avi'),
		'image'   => array('gif','jpg','jpeg','png','svg','ico','tiff','tff','swg','psd','pdd','bmp','rle','eps','jpe','pcx','pct','raw','dib'),
		'conf'    => array('ini','conf','cfg'),
		'web'     => array('html','htm','asp','cfm','php','php3','php4','php5','shtm','shtml','xhtml','xml','wdsl','xsl','rss','rdf','dtd','xsd','css','js','asa','tpl','wml','vtm','vtml'),
		'archive' => array('rpm','zip','rar','bz2','gz','gzip','arj','lzh','tar','dep')
	);
	while (list(,$file) = each($files)) {
		$icon = 'document.gif';
		if (($pos = strrpos($file['name'],'.')) !== FALSE) {
			$pos++;
			$ext = strtolower(substr($file['name'],$pos));
			reset($icons);
			while (list($icon_name,$exts) = each($icons)) {
				if (in_array($ext,$exts)) {
					$icon = 'document-'.$icon_name.'.gif';
				}
			}
		}
		echo "<tr>\n".
			 "<td class=fileentry><a href=\"$current_path{$file['name']}\" target=_new><img src=\"$conf_images/$icon\" width=16 height=16 align=left>{$file['name']}{$file['link']}</a></td>\n".
			 "<td class=fileentry nowrap>{$file['size']}</td>\n".
			 "<td class=fileentry nowrap>{$file['date']}</td>\n".
			 "<td class=fileentry nowrap>{$file['owner']}</td>\n".
			 "<td class=fileentry nowrap>{$file['group']}</td>\n".
			 "<td class=fileentry nowrap>{$file['perm']}</td>\n".
		 	 "<td class=fileentry align=center><a href=\"{$_SERVER['PHP_SELF']}?file=$current_path{$file['name']}&event=download\" alt=\"Download this file\"><img src=\"$conf_images/save.gif\" width=16 height=16></a></td>\n".
			 "<td class=fileentry><input type=checkbox name=selected[] value=\"$current_path{$file['name']}\"></td>\n".
			 "</tr>\n";
	}
	if (isset($_SESSION['action']) && ($_SESSION['action'] == 'cut' || $_SESSION['action'] == 'copy')) {
		$disabled = '';
	} else {
		$disabled = 'DISABLED';
	}
	echo "<tr><td height=100% valign=bottom align=left colspan=8><p align=center><br>\n".
		 "<input class=button type=\"button\" value=\"Cut File(s)\" onClick=\"document.selectform.action.value='cut'; document.selectform.submit();\">".
		 "<input class=button type=\"button\" value=\"Copy File(s)\" onClick=\"document.selectform.action.value='copy'; document.selectform.submit();\">".
		 "<input class=button type=\"button\" value=\"Paste File(s)\" onClick=\"document.selectform.action.value='paste'; document.selectform.submit();\" $disabled>".
		 "<input class=button type=\"button\" value=\"Delete File(s)\" onClick=\"if (confirm('Delete file(s)?')) { document.selectform.action.value='delete'; document.selectform.submit(); }\">".
		 "<input class=button type=\"button\" value=\"Create Dir\" onClick=\"window.open('{$_SERVER['PHP_SELF']}?event=mkdir&path=$current_path','win_create','width=320,height=140,fullscreen=no,scrollbars=no,resizable=no,status=no,toolbar=no,menubar=no,location=no')\">".
		 "<input class=button type=\"button\" value=\"Upload File\" onClick=\"window.open('{$_SERVER['PHP_SELF']}?event=upload&path=$current_path','win_upload','width=320,height=140,fullscreen=no,scrollbars=no,resizable=no,status=no,toolbar=no,menubar=no,location=no')\">".
		 "<input class=button type=\"button\" value=\"Change Passwd\" onClick=\"window.open('{$_SERVER['PHP_SELF']}?event=passwd','win_passwd','width=320,height=140,fullscreen=no,scrollbars=no,resizable=no,status=no,toolbar=no,menubar=no,location=no')\">".
		 "<br>&nbsp;</p></td></tr>\n".
		 "</form>\n".
	 	 "</table>\n".
		 "</div>\n".
	     "</td>\n</tr>\n";
}


function draw_tree()
{
	global $current_path, $conf_images;
	echo "<tr><td width=200 align=left valign=top height=100%>\n".
		 "<div class=\"treeview\">\n".
		 "<table cellspacing=0 cellpadding=0>";
	recurse_draw_tree(get_tree($current_path,0),'','');
	echo "</table>\n".
		 "</div>\n".
	     "</td>\n";

}


function recurse_draw_tree($tree, $spacing, $parent)
{
	global $conf_images;
	$tree_mid      = "<img align=left src=\"$conf_images/tree-mid.gif\" width=16 height=22 vspace=0 hspace=0>";
	$tree_end      = "<img align=left src=\"$conf_images/tree-end.gif\" width=16 height=22 vspace=0 hspace=0>";
	$tree_blank    = "<img align=left src=\"$conf_images/tree-blank.gif\" width=16 height=22 vspace=0 hspace=0>";
	$tree_straight = "<img align=left src=\"$conf_images/tree-straight.gif\" width=16 height=22 vspace=0 hspace=0>";
	$folder_open   = "<img align=left src=\"$conf_images/tree-folder-open.gif\" width=24 height=22 vspace=0 hspace=0>";
	$folder_closed = "<img align=left src=\"$conf_images/tree-folder-closed.gif\" width=24 height=22 vspace=0 hspace=0>";
	if ($parent == '') {
		// top level, draw /
		echo "<tr><td height=22 align=left><a href=\"{$_SERVER['PHP_SELF']}?path=/\">$folder_open/</a></td></tr>\n";
	}
	$count = count($tree);
	$i     = 0;
	reset($tree);
	while (list($dir,$subs) = each($tree)) {
		$result = $spacing;
		if ($i != $count-1) {
			$result .= $tree_mid;
		} else {
			$result .= $tree_end;
		}
		if ($subs !== FALSE) {
			$result .= $folder_open;
		} else { // not expanded
			$result .= $folder_closed;
		}
		$result .= $dir;
		echo "<tr><td height=22 align=left><a href=\"{$_SERVER['PHP_SELF']}?path=$parent/$dir\">$result</a></td></tr>\n";
		if ($subs !== FALSE) {
			if ($i == $count-1) {
				$sp = $tree_blank;
			} else {
				$sp = $tree_straight;
			}   
			$sp = $spacing.$sp;
			recurse_draw_tree($subs,$sp,"$parent/$dir");
		}
		$i++;
	}
}


function get_tree($path,$level)
{
	global $conf_chroot,$conf_sort_dirs;
	$path_components = explode('/',$path);
	$current_path    = $conf_chroot."/";
	$ret             = array();
	// construct the path we are now traversing
	if ($level > 0) {
		for ($i = 0 ; $i <= $level; $i++) {
			$current_path .= $path_components[$i]."/";
		}
	}
	if (($dh = opendir($current_path)) === FALSE) {
		die("Fatal error trying to read directory: $path<br>");
	}
	while (($dir = readdir($dh)) !== FALSE) {
		if (is_dir("$current_path/$dir") && $dir != '.' && $dir != '..') {
			if ((count($path_components) > ($level+1)) && $dir == $path_components[$level+1]) {
				$ret[$dir] = get_tree($path,$level+1);
			} else {
				$ret[$dir] = FALSE;
			}
		}
	}
	closedir($dh);
	if ($conf_sort_dirs) ksort($ret);
	return $ret;
}


function load_ids()
{
	global $conf_resolveid, $id_passwd, $id_group;
	if ($conf_resolveid) {
		exec("cat /etc/passwd",$id_passwd);
		exec("cat /etc/group",$id_group);
	} else {
		$id_passwd = FALSE;
		$id_group  = FALSE;
	}
}


function resolve_user($arg)
{
    global $id_passwd;
	$aux = "x:".trim($arg).":";
	for ($i = 0 ; $i < count($id_passwd) ; $i++){
        if (strstr($id_passwd[$i],$aux)){
         $id = explode(":",$id_passwd[$i]);
         return $id[0];
        }
    }
	if ($arg == 0) {
		$arg = '0';
	}
    return $arg;
}


function resolve_group ($arg)
{
    global $id_group;
    $aux = "x:".trim($arg).":";
    for ($i = 0 ; $i < count($id_group) ; $i++){
		if (strstr($id_group[$i],$aux)){
         $id = explode(":",$id_group[$i]);
         return $id[0];
        }
    }
	if ($arg == 0) {
		$arg = '0';
	}
    return $arg;
}


function resolve_perms($perms)
{
	if (($perms & 0xC000) == 0xC000) {
		$info = 's';
	} elseif (($perms & 0xA000) == 0xA000) {
		$info = 'l';
	} elseif (($perms & 0x8000) == 0x8000) {
		$info = '-';
	} elseif (($perms & 0x6000) == 0x6000) {
		$info = 'b';
	} elseif (($perms & 0x4000) == 0x4000) {
		$info = 'd';
	} elseif (($perms & 0x2000) == 0x2000) {
		$info = 'c';
	} elseif (($perms & 0x1000) == 0x1000) {
		$info = 'p';
	} else {
		$info = 'u';
	}
	$info .= (($perms & 0x0100) ? 'r' : '-');
	$info .= (($perms & 0x0080) ? 'w' : '-');
	$info .= (($perms & 0x0040) ?
             (($perms & 0x0800) ? 's' : 'x' ) :
             (($perms & 0x0800) ? 'S' : '-'));
	// Group
	$info .= (($perms & 0x0020) ? 'r' : '-');
	$info .= (($perms & 0x0010) ? 'w' : '-');
	$info .= (($perms & 0x0008) ?
             (($perms & 0x0400) ? 's' : 'x' ) :
             (($perms & 0x0400) ? 'S' : '-'));
	// World
	$info .= (($perms & 0x0004) ? 'r' : '-');
	$info .= (($perms & 0x0002) ? 'w' : '-');
	$info .= (($perms & 0x0001) ?
             (($perms & 0x0200) ? 't' : 'x' ) :
             (($perms & 0x0200) ? 'T' : '-'));
	return $info;
}


function format_size($arg)
{
	if ($arg > 0) {
		$j = 0;
		$ext = array(" bytes"," Kb"," Mb"," Gb"," Tb");
		while ($arg >= pow(1024,$j)) {
			++$j;
		}
		return round($arg / pow(1024,$j-1) * 100) / 100 . $ext[$j-1];
	}
	return "0 Mb";
}


function format_path($str)
{
	$str  = trim(str_replace("..","",str_replace("\\","/",str_replace("\$","",$str))));
	while ($str != ($str = str_replace("//","/",$str))) {}
    if (strlen($str)) {
		if ($str[0] != "/") {
			$str = "/".$str;
		}
        if ($str[strlen($str)-1] != "/") {
			$str .= "/";
		}
    } else {
		$str = "/";
	}
	return $str;
}


function draw_header()
{
	global $conf_images;
?>
<html>
<head>
<LINK href="<? echo $conf_images; ?>/fm.css" rel=stylesheet>
<title>WebFM 0.1</title>
</head>
<body>
<table cellspacing=0 cellpadding=0 width="100%" height="100%">
<?
}

function draw_footer()
{
	global $total_dirs, $total_files, $total_size, $conf_chroot, $current_path,
		$conf_images;
?>
<tr>
<td colspan=2>
<div class="taskfooter">
<? echo "$total_dirs directory(s) and $total_files file(s) in ".format_size($total_size).". Diskspace : ".
format_size(disk_free_space("$conf_chroot$current_path"))." Free of ".format_size(disk_total_space("$conf_chroot$current_path"));
?>.
</div>
</td>
</tr>
</table>
</body>
</html>
<?
}

function draw_location()
{
	global $current_path,$conf_images;
?>
<tr><td colspan=2 align=left valign=top width=100%>
<div class="taskheader">
	<div class="taskgradient"></div>
	<div class="taskicon"><img src="<? echo $conf_images; ?>/task_view.gif" width="32" height="32"></a></div>
	<div class="tasktitle">Browsing <? echo $current_path; ?></div>
</div>
</td></tr>
<?
}


$passwd_warn = !authenticate();

if (isset($_GET['event'])) {
	$event = $_GET['event'];
} else {
	$event = 'view';
}

if (isset($_GET['path'])) {
	$current_path = format_path($_GET['path']);
} else {
	$current_path = '/';
}

session_start();
if (isset($_POST['action'])) {
	switch ($_POST['action']) {
		case 'cut':
		case 'copy':
			if (isset($_POST['selected']) && count($_POST['selected'])) {
				$_SESSION['source']   = $current_path;
				$_SESSION['action']   = $_POST['action'];
				$_SESSION['selected'] = $_POST['selected'];
			}
			break;
		case 'paste':
			if ($_SESSION['action'] != 'copy' || $_SESSION['action'] != 'cut') {
				if (isset($_SESSION['source']) && $_SESSION['source'] != $current_path) {
					paste_or_delete($_SESSION['action']);
					if (isset($_SESSION['source']))   unset($_SESSION['source']);
					if (isset($_SESSION['selected'])) unset($_SESSION['selected']);
					if (isset($_SESSION['action']))   unset($_SESSION['action']);
				} else {
					echo "\n<script>alert('Can\'t {$_SESSION['action']} file(s). Source and destination are the same');</script>\n";
				}
			}
			break;
		case 'delete':
			$_SESSION['action']   = $_POST['action'];
			$_SESSION['selected'] = $_POST['selected'];
			paste_or_delete('delete');
			if (isset($_SESSION['source']))   unset($_SESSION['source']);
			if (isset($_SESSION['selected'])) unset($_SESSION['selected']);
			if (isset($_SESSION['action']))   unset($_SESSION['action']);
			break;
		default:
			die("Invalid action specified");
			break;
	}
}


switch ($event) {
	case 'view':
		if ($passwd_warn) {
			echo "<script>alert('No password set, please do this right away');</script>\n";
		}
		load_ids();
		draw_header();
		draw_location();
		draw_tree();
		draw_files();
		draw_footer();
		break;
	case 'upload':
		upload_file();
		break;
	case 'mkdir':
		make_directory();
		break;
	case 'download':
		download_file();
		break;
	case 'passwd':
		change_passwd();
		break;
	default:
		die('Invalid event');
		break;
}


?>