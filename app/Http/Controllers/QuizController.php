<?php namespace App\Http\Controllers;

use App\Config;
use App\Http\Requests;
use App\Question;
use App\Quiz;
use Input, Response, DB;

class QuizController extends Controller {

	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
	{
		$query = Quiz::query();
		
		$page = Input::query('page') ? Input::query('page') : 1;
		
		$per_page = Input::query('per_page') ? Input::query('per_page') : false;
		
		$list_total = $query->count();
		
		if($per_page)
		{
			$query->skip(($page - 1) * $per_page)->take($per_page);
			$list_start = ($page - 1) * $per_page + 1;
			$list_end = ($page - 1) * $per_page + $per_page;
			if($list_end > $list_total)
			{
				$list_end = $list_total;
			}
		}
		else
		{
			$list_start = 1; $list_end = $list_total;
		}
		
		$results = $query->get();
		
		return response($results)->header('Items-Total', $list_total)->header('Items-Start', $list_start)->header('Items-End', $list_end);
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
			abort(401, '需要登录后才能参与竞赛');
		}

		$round = Config::get('quiz_round');
		$round_time_limit = Config::get('quiz_round_time_limit')->$round;

		// check if there's an unfinished quiz of this user
		$quizzes_existed = Quiz::where('user_id', app()->user->id)->get();

		foreach($quizzes_existed as $quiz_existed)
		{
			if(is_null($quiz_existed->score) && $quiz_existed->created_at->diffInSeconds(null, false) <= $round_time_limit)
			{
				$quiz_unfinished = $quiz_existed;
				break;
			}
		}

		if(isset($quiz_unfinished))
		{
			$quiz = $quiz_unfinished;
			return $this->show($quiz);
		}
		else
		{
			$quiz = new Quiz();
			$questions = Question::where('round', $round)->orderBy(DB::raw('RAND()'))->take(10)->get();
			$quiz->questions = $questions;
			$quiz->round = $round;
			$quiz->duration = null;
			$quiz->score = null;
			$quiz->user()->associate(app()->user);
			return $this->update($quiz);
		}
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  Quiz $quiz
	 * @return Response
	 */
	public function show($quiz)
	{
		$quiz->setAppends(['timeout_at', 'attempts', 'attempts_allowed']);
		return $quiz;
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  Quiz $quiz
	 * @return Response
	 */
	public function update($quiz)
	{
		if(!is_null(Input::query('question_id')) && !is_null(Input::query('user_answer')) && !isset($quiz->questions[Input::query('question_id')]->user_answer))
		{
			$question_id = Input::query('question_id');
			$questions = $quiz->questions;
			$user_answer = Input::query('user_answer');

			$choice_labels = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

			if(is_int($user_answer) && isset($choice_labels[$user_answer]))
			{
				$user_answer = $choice_labels[$user_answer];
			}

			$questions[$question_id]->user_answer = $user_answer;
			$questions[$question_id]->user_answer_correct = ($questions[$question_id]->answer === $user_answer);
			$quiz->questions = $questions;
		}

		if(Input::query('finish'))
		{
			$quiz->duration = time() - $quiz->created_at->timestamp;

			$unanswered_questions = collect($quiz->questions)->filter(function($question)
			{
				return !isset($question->user_answer_correct);
			});

			if($unanswered_questions->count() > 0)
			{
				abort(400, '还有题目未答, 不能交卷');
			}

			$correct_answers = collect($quiz->questions)->filter(function($question)
			{
				return $question->user_answer_correct;
			}
			)->count();

			if($quiz->round === 1)
			{
				$quiz->score = $correct_answers >= 5 ? $correct_answers : 0;
			}
			else
			{
				$quiz->score = $correct_answers;
			}
		}

		$quiz->save();
		return $this->show($quiz);
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  Quiz $quiz
	 * @return Response
	 */
	public function destroy($quiz)
	{
		$quiz->delete();
	}

}