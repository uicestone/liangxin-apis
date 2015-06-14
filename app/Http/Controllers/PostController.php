<?php namespace App\Http\Controllers;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Post;
use Input, Exception;

class PostController extends Controller {

	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
	{
		
		$query = Post::with('group', 'author', 'poster');
		
		foreach(['type', 'author_id', 'parent_id', 'group_id', 'event_type', 'class_type'] as $field)
		{
			if(Input::query($field))
			{
				$query->where($field, Input::query($field));
			}
		}
		
		if(Input::query('keyword'))
		{
			$query->where('title', 'like', '%' . Input::query('keyword') . '%');
		}
		
		if(Input::query('position') && Input::query('type') === '横幅')
		{
			$query->where('banner_position', Input::query('position'));
		}
		
		if(Input::query('liked_user_id'))
		{
			$query->whereHas('likedUsers', function($query)
			{
				return $query->where('user_id', Input::query('liked_user_id'));
			});
		}
		
		if(!Input::query('order_by') && !Input::query('order'))
		{
			$order_by = 'created_at'; $order = 'desc';
		}
		else
		{
			$order_by = Input::query('order_by');
			$order = Input::query('order');
		}
		
		if($order_by)
		{
			$query->orderBy($order_by, $order ? $order : 'asc');
		}
		
		$page = Input::query('page') ? Input::query('page') : 1;
		
		$per_page = Input::query('per_page') ? Input::query('per_page') : 10;
		$query->skip(($page - 1) * $per_page)->take($per_page);
		
		return $query->get()->map(function($post)
		{
			
			$post->addVisible('group', 'author');
			
			if($post->type === '活动')
			{
				$post->addVisible(['event_address', 'event_type', 'has_due_date']);
				
				if($post->due_date > 0)
				{
					$post->addVisible('due_date');
				}
				
				if($post->event_date > 0)
				{
					$post->addVisible('event_date');
				}
				
				$post->has_due_date = $post->has_due_date;
			}
			
			if($post->type === '课堂')
			{
				$post->addVisible(['class_type']);
			}
			
			if($post->type === '横幅')
			{
				$post->addVisible(['banner_position', 'poster']);
			}
			
			if(in_array($post->type, ['横幅', '图片', '视频', '附件']))
			{
				$post->addVisible(['url']);	
			}
			
			$post->liked = $post->liked;
			$post->is_favorite = $post->is_favorite;

			return $post;
		});
		
	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store()
	{
		if(!app()->user)
		{
			throw new Exception('Authentication is required for this action.', 401);
		}
		
		$post = new Post();
		$post->fill(Input::data());
		
		$post->author()->associate(app()->user);
		
		if(app()->user->group)
		{
			$post->group()->associate(app()->user->group);
		}
		
		if(Input::data('parent_id'))
		{
			$parent_post = Post::find(Input::data('parent_id'));
			
			if(!$parent_post)
			{
				throw new Exception('Parent post id: ' . Input::data('parent_id') . ' not found', 400);
			}
			
			$post->parent()->associate($parent_post);
		
		}
		
		$post->save();
		
		// upload files and create child posts
		foreach(['images', 'attachments'] as $file_type)
		{
			if(!is_array(Input::data($file_type)) || !Input::data($file_type)[0]->isValid())
			{
				break;
			}
			
			foreach(Input::data($file_type) as $file)
			{
				$file_store_name = md5($file->getClientOriginalName() . time() . env('APP_KEY')) . '.' . $file->getClientOriginalExtension();
				$file->move(public_path($file_type), $file_store_name);
				
				$file_post = new Post();
				
				$file_post->fill([
					'title'=>$file->getClientOriginalName(),
					'type'=>$file_type === 'images' ? '图片' : '附件',
					'url'=>$file_type . '/' . $file_store_name,
				]);
				
				$file_post->parent()->associate($post);
				$file_post->author()->associate(app()->user);
				
				if(app()->user->group)
				{
					$file_post->group()->associate(app()->user->group);
				}
				
				$file_post->save();
			}
			
			$post->$file_type = $post->$file_type;
		}
		
		return $post;
		
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  Post $post
	 * @return Response
	 */
	public function show(Post $post)
	{
		$post->load('likedUsers', 'author', 'poster', 'parent');
		$post->addVisible('likedUsers', 'author', 'parent', 'comments', 'liked', 'is_favorite');
		
		$post->comments = $post->comments;
		
		$post->liked = $post->liked;
		$post->is_favorite = $post->is_favorite;
		
		if($post->type === '文章')
		{
			$post->addVisible(['content']);
		}
		
		if($post->type === '活动')
		{
			$post->addVisible(['event_date', 'event_address', 'event_type', 'due_date', 'has_due_date', 'content', 'excerpt', 'attendees']);
			$post->load('attendees');
			$post->has_due_date = $post->has_due_date;
		}

		if($post->type === '课堂')
		{
			$post->addVisible(['class_type', 'videos', 'articles', 'attachments']);
			$post->videos = $post->videos;
			$post->articles = $post->articles;
			$post->attachments = $post->attachments;
		}

		if($post->type === '横幅')
		{
			$post->addVisible(['banner_position', 'poster']);
		}

		if(in_array($post->type, ['附件', '图片', '视频', '横幅']))
		{
			$post->addVisible(['url']);
		}
		
		if(in_array($post->type, ['活动', '课堂', '文章']))
		{
			$post->addVisible('images');
			$post->images = $post->images;
		}
		
		return $post;
	}

	/**
	 * Display a post directly
	 */
	public function display(Post $post)
	{
		return view('post', compact('post'));
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  Post  $post
	 * @return Response
	 */
	public function update(Post $post)
	{
		$post->fill(Input::data());
		
		$post->author()->associate(app()->user);
		$post->group()->associate(app()->user->group);
		
		if(Input::data('parent_id'))
		{
			$parent_post = Post::find(Input::data('parent_id'));
			
			if(!$parent_post)
			{
				throw new Exception('Parent post id: ' . Input::data('parent_id') . ' not found', 400);
			}
			
			$post->parent()->associate($parent_post);
		
		}
		
		$post->save();
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  Post  $post
	 * @return Response
	 */
	public function destroy($post = null)
	{
		if(is_null($post) && Input::query('id'))
		{
			$ids = Input::query('id');
			
			if(!is_array($ids))
			{
				$ids = [$ids];
			}
			
			$posts = Post::whereIn('id', $ids)->get();
			
			$posts->each(function($post)
			{
				try{
					$this->destroy($post);
				}
				catch(Exception $e)
				{
					
				}
			});
			
			return;
		}
		
		if(!app()->user)
		{
			throw new Exception('用户没有登录，无法删除该文章', 401);
		}

		if(!$post->author || app()->user->id !== $post->author->id)
		{
			throw new Exception('用户不是文章的作者，无权删除该文章', 403);
		}
		
		try{
			$post->delete();
		}
		catch(\Illuminate\Database\QueryException $e)
		{
			if($e->getCode() === '23000')
			{
				throw new Exception('该文章是其他文章的上级文章，无法删除', 400);
			}
		}
	}
	
	public function like(Post $post)
	{
		if(!app()->user)
		{
			throw new Exception('用户没有登录，无法点赞该文章', 401);
		}
		
		if($post->likedUsers->contains(app()->user->id))
		{
			throw new Exception('用户已经点赞该文章，无法重复点赞', 409);
		}
		
		$post->likes = $post->likedUsers()->count();
		$post->save();
		
		return $post->likedUsers()->attach(app()->user);
	}
	
	public function unLike(Post $post)
	{
		if(!app()->user)
		{
			throw new Exception('用户没有登录，无法取消点赞该文章', 401);
		}
		
		if(!$post->likedUsers->contains(app()->user->id))
		{
			throw new Exception('用户尚未点赞该文章，无法取消点赞', 409);
		}
		
		$post->likes = $post->likedUsers()->count();
		$post->save();
		
		return $post->likedUsers()->detach(app()->user);
	}

	public function favorite(Post $post)
	{
		if(!app()->user)
		{
			throw new Exception('用户没有登录，无法收藏该文章', 401);
		}
		
		if($post->favoredUsers->contains(app()->user->id))
		{
			throw new Exception('用户已经收藏该文章，无法重复收藏', 409);
		}
		
		$post->likes = $post->favoredUsers()->count();
		$post->save();
		
		return $post->favoredUsers()->attach(app()->user);
	}
	
	public function unFavorite(Post $post)
	{
		if(!app()->user)
		{
			throw new Exception('用户没有登录，无法取消收藏该文章', 401);
		}
		
		if(!$post->likedUsers->contains(app()->user->id))
		{
			throw new Exception('用户尚未收藏该文章，无法取消收藏', 409);
		}
		
		$post->likes = $post->likedUsers()->count();
		$post->save();
		
		return $post->likedUsers()->detach(app()->user);
	}

	public function attend(Post $event)
	{
		if(!app()->user)
		{
			throw new Exception('用户没有登录，无法参与该活动', 401);
		}
		
		if($event->attendees->contains(app()->user->id))
		{
			throw new Exception('用户已经参与该活动，无法重复参与', 409);
		}
		
		return $event->attendees()->attach(app()->user);
	}
	
	public function unAttend(Post $event)
	{
		if(!app()->user)
		{
			throw new Exception('用户没有登录，无法取消参与该活动', 401);
		}
		
		if(!$event->attendees->contains(app()->user->id))
		{
			throw new Exception('用户尚未参与该活动，无法取消参与', 409);
		}
		
		return $event->attendees()->detach(app()->user);
	}

}
