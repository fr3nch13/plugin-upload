<?php
App::uses('HtmlHelper', 'View/Helper');

class AvatarHelper extends HtmlHelper 
{
	
	private $options = array(
		'path' => 'img',
		'id_field' => 'User.id',
		'photo_field' => 'User.photo',
		'alt_field' => 'User.name',
		'default_image_path' => '/img/fileicons/jpeg.png',
		'class_base' => 'avatarhelper',
	);
	
	private $View;
	
	public function __construct(View $view, $options = array()) {
		parent::__construct($view, $options);
		$this->options = array_merge($this->options, $options);
		$this->View = &$view;
    }
    
	public function view($data=array(), $size = false)
	{
	/*
	 * Displays the avatar
	 */
		$id = Set::extract($data, $this->options['id_field']);
		$file = Set::extract($data, $this->options['photo_field']);
		$alt = Set::extract($data, $this->options['alt_field']);
		
		$url = explode('/', $this->options['path']);
		$url[] = $id;
		if($size)
		{
			$file = $size.'_'.$file;
		}
		$url[] = $file;
		$url = implode('/', $url);
		
		// check to make sure the file exists, if not, then use the default
		$file_path = str_replace(DS.DS, DS, WWW_ROOT. $url);
		if(!file_exists($file_path))
		{
			$url = $this->options['default_image_path'];
		}
		return $this->image($url, array('alt' => $alt, 'class' => $this->options['class_base']. ' '. $this->options['class_base']. '_'. $size));
	}
	
	public function avatarPreview($size = 'tiny', $previewSize = 'big')
	{
	/*
	 * writes out the code to show the larger image when hovering over a small avatar image
	 */
		$class = '.'.$this->options['class_base']. '_'. $size;
		$size .= '_';
		$previewSize .= '_';
		
		$codeBlock = <<<EOT
		/* CONFIG */
		
		xOffset = 10;
		yOffset = 30;
		
		// these 2 variable determine popup's distance from the cursor
		// you might want to adjust to get the right result
		
	/* END CONFIG */
	$("__class__").hover(function(e){
		this.t = this.title;
		this.title = "";	
		var c = (this.t != "") ? "<br/>" + this.t : "";
		var src = this.src;
		src = src.replace("__size__", "__previewSize__");
//		alert(src);
		$("body").append('<p id="avatarpreview"><img src="'+ src +'" alt="Image preview" />'+ c +'</p>');								 
		$("#avatarpreview")
			.css("top",(e.pageY - xOffset) + "px")
			.css("left",(e.pageX + yOffset) + "px")
			.fadeIn("fast");						
    },
	function(){
		this.title = this.t;	
		$("#avatarpreview").remove();
    });	
	$("__class__").mousemove(function(e){
		$("#avatarpreview")
			.css("top",(e.pageY - xOffset) + "px")
			.css("left",(e.pageX + yOffset) + "px");
	});
EOT;
		$codeBlock = str_replace('__size__', $size, $codeBlock);
		$codeBlock = str_replace('__previewSize__', $previewSize, $codeBlock);
		$codeBlock = str_replace('__class__', $class, $codeBlock);
		$this->Js->buffer($codeBlock);
	}
}
?>