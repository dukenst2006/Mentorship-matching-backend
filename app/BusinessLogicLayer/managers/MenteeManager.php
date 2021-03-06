<?php

namespace App\BusinessLogicLayer\managers;

use App\Models\eloquent\MenteeProfile;
use App\Models\viewmodels\MenteeViewModel;
use App\Notifications\MenteeRegistered;
use App\StorageLayer\MenteeStorage;
use App\StorageLayer\RawQueryStorage;
use App\Utils\MentorshipSessionStatuses;
use App\Utils\RawQueriesResultsModifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MenteeManager {

    private $menteeStorage;
    private $specialtyManager;

    public function __construct() {
        $this->menteeStorage = new MenteeStorage();
        $this->specialtyManager = new SpecialtyManager();
    }

    public function getAllMentees() {
        return $this->menteeStorage->getAllMenteeProfiles();
    }

    /**
     * Gets all the mentees transformed to view model
     *
     * @return Collection MenteeViewModel
     */
    public function getAllMenteeViewModels() {
        $mentees = $this->menteeStorage->getAllMenteeProfiles();
        return $this->getMenteeViewModelsFromCollection($mentees);
    }

    public function getAvailableMenteeViewModels() {
        $menteeStatusManager = new MenteeStatusManager();
        $mentees = $this->menteeStorage->getMenteeProfilesWithStatusId($menteeStatusManager->MENTEE_AVAILABLE_ID);
        return $this->getMenteeViewModelsFromCollection($mentees);
    }

    private function getMenteeViewModelsFromCollection(Collection $mentees) {
        $menteeViewModels = new Collection();
        foreach ($mentees as $mentee) {
            $menteeViewModels->add($this->getMenteeViewModel($mentee));
        }
        return $menteeViewModels;
    }

    /**
     * Trandform a mentee to the according view model
     *
     * @param MenteeProfile $mentee
     * @return MenteeViewModel
     */
    public function getMenteeViewModel(MenteeProfile $mentee) {
        $menteeViewModel = new MenteeViewModel($mentee);
        return $menteeViewModel;
    }

    /**
     * Store a cv file with hashed file name
     *
     * @param UploadedFile $cvFile
     * @param $menteeEmail
     * @return string
     */
    private function saveCVFile(UploadedFile $cvFile, $menteeEmail) {
        $fileName = md5($menteeEmail . Carbon::now());
        $originalFileExtension = $cvFile->extension();
        $fullFileName = $fileName . "." . $originalFileExtension;
        $cvFile->move("uploads/cv_files", $fullFileName);
        return $fullFileName;
    }

    public function createMentee(array $inputFields, $isCvFileExistent) {
        $loggedInUser = Auth::user();
        if($loggedInUser != null) {
            $inputFields['creator_user_id'] = $loggedInUser->id;
        }
        // store the file and put the file's name to the DB
        if($isCvFileExistent){
            $fileName = $this->saveCVFile($inputFields['cv_file'], $inputFields['email']);
            $inputFields['cv_file_name'] = $fileName;
        }
        $menteeProfile = new MenteeProfile();
        $menteeProfile = $this->assignInputFieldsToMenteeProfile($menteeProfile, $inputFields);

        DB::transaction(function() use($menteeProfile, $inputFields) {
            $newMentee = $this->menteeStorage->saveMentee($menteeProfile);
            if($inputFields['public_form'] == "true")
                $newMentee->notify(new MenteeRegistered());
        });
    }

    /**
     * @param MenteeProfile $menteeProfile the instance
     * @param array $inputFields the array of input fields
     * @param boolean checks whether a new mentee profile is created or not
     * @return MenteeProfile the instance with the fields assigned
     */
    private function assignInputFieldsToMenteeProfile(MenteeProfile $menteeProfile, array $inputFields) {
        if (isset($inputFields['is_employed']))
            $inputFields['is_employed'] = true;
        else
            $inputFields['is_employed'] = false;
        $menteeProfile->fill($inputFields);
        return $menteeProfile;
    }

    public function getMentee($id) {
        $mentee = $this->menteeStorage->getMenteeProfileById($id);
        $mentee->age = intval(date("Y")) - intval($mentee->year_of_birth);
        return $mentee;
    }

    public function editMentee(array $inputFields, $id, $isCvFileExistent = false) {
        if(isset($inputFields['do_not_contact']) || !isset($inputFields['follow_up_date'])) {
            $inputFields['follow_up_date'] = "";
        }
        if($inputFields['follow_up_date'] != "") {
            $dateArray = explode("/", $inputFields['follow_up_date']);
            $inputFields['follow_up_date'] = Carbon::createFromDate($dateArray[2], $dateArray[1], $dateArray[0]);
        }
        // store the file and put the file's name to the DB
        if($isCvFileExistent){
            $fileName = $this->saveCVFile($inputFields['cv_file'], $inputFields['email']);
            $inputFields['cv_file_name'] = $fileName;
        }
        $mentee = $this->getMentee($id);
        $oldStatusId = $mentee->status_id;
        unset($mentee->age);
        $mentee = $this->assignInputFieldsToMenteeProfile($mentee, $inputFields);
        $menteeStatusHistoryManager = new MenteeStatusHistoryManager();
        $loggedInUser = Auth::user();

        DB::transaction(function() use($mentee, $oldStatusId, $inputFields, $menteeStatusHistoryManager, $loggedInUser) {
            $this->menteeStorage->saveMentee($mentee);
            if($oldStatusId != $inputFields['status_id']) {
                $menteeStatusHistoryManager->createMenteeStatusHistory($mentee, $inputFields['status_id'],
                    (isset($inputFields['status_history_comment'])) ? $inputFields['status_history_comment'] : "",
                    ($inputFields['follow_up_date'] != "") ?
                        $inputFields['follow_up_date'] : null, $loggedInUser);
            }
        });
    }

    public function deleteMentee($menteeId) {
        $mentee = $this->getMentee($menteeId);
        $mentee->delete();
    }

    /**
     * Queries the mentees DB table to find string in name or email
     *
     * @param $searchQuery string the name or email that we need to check for
     * @return Collection the mentees that match
     */
    public function filterMenteesByNameAndEmail($searchQuery) {
        return $this->menteeStorage->getMenteesThatMatchGivenNameOrEmail($searchQuery);
    }

    /**
     * Gets all the filters passed and returns the filtered results
     *
     * @param $filters
     * @return Collection|void|static[]
     * @throws \Exception When filters aren't valid
     */
    private function getMenteesByCriteria($filters) {
        if((!isset($filters['menteeName']) || $filters['menteeName'] === "") &&
            (!isset($filters['ageRange']) || $filters['ageRange'] === "") &&
            (!isset($filters['educationLevel']) || $filters['educationLevel'] === "") &&
            (!isset($filters['university']) || $filters['university'] === "") &&
            (!isset($filters['skills']) || $filters['skills'] === "") &&
            (!isset($filters['signedUpAgo']) || $filters['signedUpAgo'] === "") &&
            (!isset($filters['completedSessionAgo']) || $filters['completedSessionAgo'] === "") &&
            (!isset($filters['displayOnlyUnemployed']) || $filters['displayOnlyUnemployed'] === 'false') &&
            (!isset($filters['displayOnlyActiveSession']) || $filters['displayOnlyActiveSession'] === 'false') &&
            (!isset($filters['displayOnlyNeverMatched']) || $filters['displayOnlyNeverMatched'] === 'false') &&
            (!isset($filters['displayOnlyExternallySubscribed']) || $filters['displayOnlyExternallySubscribed'] === 'false')) {
            return $this->menteeStorage->getAllMenteeProfiles();
        }
        $mentorshipSessionStatuses = null;
        $whereClauseExists = false;
        $dbQuery = "select distinct mp.id 
            from mentee_profile as mp 
            left outer join mentorship_session as ms on mp.id = ms.mentee_profile_id
            left outer join mentorship_session_history as msh on ms.id = msh.mentorship_session_id ";
        if(isset($filters['displayOnlyActiveSession']) && $filters['displayOnlyActiveSession'] === 'true') {
            $dbQuery .= "left outer join (select ms.id, max(msh.updated_at) as session_date 
	            from mentorship_session_history as msh join mentorship_session as ms on 
	            msh.mentorship_session_id = ms.id join mentee_profile as mp on mp.id = ms.mentee_profile_id 
	            group by mp.id, ms.id)	as last_session on (last_session.id = ms.id and last_session.session_date = msh.updated_at) ";
        }
        $dbQuery .= "where ";
        if(isset($filters['menteeName']) && $filters['menteeName'] != "") {
            $dbQuery .= "(mp.first_name like '%" . $filters['menteeName'] . "%' or mp.last_name like '%" . $filters['menteeName'] . "%') ";
            $whereClauseExists = true;
        }
        if(isset($filters['educationLevel']) && $filters['educationLevel'] != "") {
            if(intval($filters['educationLevel']) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "mp.education_level_id = " . $filters['educationLevel'] . " ";
            $whereClauseExists = true;
        }
        if(isset($filters['university']) && $filters['university'] != "") {
            if(intval($filters['university']) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "mp.university_id = " . $filters['university'] . " ";
            $whereClauseExists = true;
        }
        if(isset($filters['displayOnlyActiveSession']) && $filters['displayOnlyActiveSession'] === 'true') {
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $mentorshipSessionStatuses = new MentorshipSessionStatuses();
            $dbQuery .= "(last_session.session_date is not null and msh.status_id in (" .
                implode(",", $mentorshipSessionStatuses::getActiveSessionStatuses()) . ")) ";
            $whereClauseExists = true;
        }
        if(isset($filters['ageRange']) && $filters['ageRange'] != "") {
            $ageRange = explode(';', $filters['ageRange']);
            if(intval($ageRange[0]) == 0 || intval($ageRange[1]) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "(mp.year_of_birth > year(curdate()) - " . $ageRange[1] . " and mp.year_of_birth < year(curdate()) - " . $ageRange[0] . ") ";
            $whereClauseExists = true;
        }
        if(isset($filters['skills']) && $filters['skills'] != "") {
            $allSkills = explode(",", $filters['skills']);
            foreach ($allSkills as $skill) {
                if($whereClauseExists) {
                    $dbQuery .= "and ";
                }
                $dbQuery .= "mp.skills like '%" . $skill . "%' ";
                $whereClauseExists = true;
            }
        }
        if(isset($filters['signedUpAgo']) && $filters['signedUpAgo'] != "") {
            if(intval($filters['signedUpAgo']) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            if($filters['signedUpAgo'] < 13) {
                $dbQuery .= "mp.created_at between (now() - interval " . $filters['signedUpAgo'] .
                    " month) and (now() - interval " . ($filters['signedUpAgo'] - 1) . " month) ";
            } else {
                $dbQuery .= "mp.created_at < (now() - interval 12 month) ";
            }
            $whereClauseExists = true;
        }
        if(isset($filters['completedSessionAgo']) && $filters['completedSessionAgo'] != "") {
            if(intval($filters['completedSessionAgo']) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            if($mentorshipSessionStatuses == null) {
                $mentorshipSessionStatuses = new MentorshipSessionStatuses();
            }
            $dbQuery .= "msh.status_id in (" . implode(",", $mentorshipSessionStatuses::getCompletedSessionStatuses()) . ") and msh.updated_at between (now() - interval " . $filters['completedSessionAgo'] . " month) 
                and (now() - interval " . ($filters['completedSessionAgo'] - 1) . " month) ";
            $whereClauseExists = true;
        }
        if(isset($filters['displayOnlyUnemployed']) && $filters['displayOnlyUnemployed'] === 'true') {
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "mp.is_employed = 0 ";
            $whereClauseExists = true;
        }
        if(isset($filters['displayOnlyNeverMatched']) && $filters['displayOnlyNeverMatched'] === 'true') {
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "ms.id is null ";
            $whereClauseExists = true;
        }
        if(isset($filters['displayOnlyExternallySubscribed']) && $filters['displayOnlyExternallySubscribed'] === 'true') {
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "mp.creator_user_id is null ";
        }
        $filteredMenteeIds = RawQueriesResultsModifier::transformRawQueryStorageResultsToOneDimensionalArray(
            (new RawQueryStorage())->performRawQuery($dbQuery)
        );
        return $this->menteeStorage->getMenteesFromIdsArray($filteredMenteeIds);
    }

    /**
     * Gets all the filters passed and returns the filtered results as their according view model
     *
     * @param $filters
     * @return Collection MenteeViewModel
     */
    public function getMenteeViewModelsByCriteria($filters) {
        $mentees = $this->getMenteesByCriteria($filters);
        $menteeViewModels = new Collection();
        foreach ($mentees as $mentee) {
            $menteeViewModels->add($this->getMenteeViewModel($mentee));
        }
        return $menteeViewModels;
    }

    /**
     * Change the mentee's availability status
     *
     * @param array $input parameters passed by user
     * @throws \Exception When something weird happens with the parameters passed
     */
    public function changeMenteeAvailabilityStatus(array $input) {
        if(isset($input['do_not_contact'])) {
            $input['follow_up_date'] = "";
        }
        if($input['follow_up_date'] != "") {
            $dateArray = explode("/", $input['follow_up_date']);
            $input['follow_up_date'] = Carbon::createFromDate($dateArray[2], $dateArray[1], $dateArray[0]);
        }
        $mentee = $this->getMentee($input['mentee_id']);
        unset($mentee->age);
        // if something wrong passed
        if($mentee == null || intval($input['status_id']) == 0) {
            throw new \Exception("Wrong parameters passed.");
        }
        $mentee->status_id = $input['status_id'];
        $loggedInUser = Auth::user();
        DB::transaction(function() use($mentee, $input, $loggedInUser) {
            $mentee = $this->menteeStorage->saveMentee($mentee);
            $menteeStatusHistoryManager = new MenteeStatusHistoryManager();
            $menteeStatusHistoryManager->createMenteeStatusHistory($mentee, $input['status_id'], $input['status_history_comment'],
                ($input['follow_up_date'] != "") ? $input['follow_up_date'] : null, $loggedInUser);
        });
    }
}
