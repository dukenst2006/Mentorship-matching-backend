<?php
/**
 * Created by IntelliJ IDEA.
 * User: pisaris
 * Date: 7/2/2017
 * Time: 2:03 μμ
 */

namespace App\BusinessLogicLayer\managers;


use App\Models\eloquent\Company;
use App\Models\eloquent\MentorProfile;
use App\Models\viewmodels\MentorViewModel;
use App\Notifications\MentorRegistered;
use App\StorageLayer\MentorStorage;
use App\StorageLayer\RawQueryStorage;
use App\Utils\MentorshipSessionStatuses;
use App\Utils\RawQueriesResultsModifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MentorManager {

    private $mentorStorage;
    private $specialtyManager;
    private $industryManager;

    public function __construct() {
        $this->mentorStorage = new MentorStorage();
        $this->specialtyManager = new SpecialtyManager();
        $this->industryManager = new IndustryManager();
    }

    /**
     * Gets all @see MentorProfile instances from the database
     *
     * @return Collection the mentors
     */
    public function getAllMentors() {
        return $this->mentorStorage->getAllMentorProfiles();
    }

    /**
     * Gets all @see MentorProfile instances from the database
     *
     * @return Collection the mentors
     */
    public function getAllMentorViewModels() {
        $mentors = $this->mentorStorage->getAllMentorProfiles();
        return $this->getMentorViewModelsFromCollection($mentors);
    }

    public function getAvailableMentorViewModels() {
        $mentorStatusManager = new MentorStatusManager();
        $mentors = $this->mentorStorage->getMentorProfilesWithStatusId($mentorStatusManager->MENTOR_AVAILABLE_ID);
        return $this->getMentorViewModelsFromCollection($mentors);
    }

    private function getMentorViewModelsFromCollection(Collection $mentors) {
        $mentorViewModels = new Collection();
        foreach ($mentors as $mentor) {
            $mentorViewModels->add($this->getMentorViewModel($mentor));
        }
        return $mentorViewModels;
    }

    /**
     * Store a cv file with hashed file name
     *
     * @param UploadedFile $cvFile
     * @param $mentorEmail
     * @return string
     */
    private function saveCVFile(UploadedFile $cvFile, $mentorEmail) {
        $fileName = md5($mentorEmail . Carbon::now());
        $originalFileExtension = $cvFile->extension();
        $fullFileName = $fileName . "." . $originalFileExtension;
        $cvFile->move("uploads/cv_files", $fullFileName);
        return $fullFileName;
    }

    /**
     * Creates a @see MentorProfile resource
     *
     * @param array $inputFields the fields to assign to the mentor
     * @param $isCvFileExistent boolean it defines whether the cv file exists or not
     */
    public function createMentor(array $inputFields, $isCvFileExistent) {
        $loggedInUser = Auth::user();
        if($loggedInUser != null)
            $inputFields['creator_user_id'] = $loggedInUser->id;
        // store the file and put the file's name to the DB
        if($isCvFileExistent){
            $fileName = $this->saveCVFile($inputFields['cv_file'], $inputFields['email']);
            $inputFields['cv_file_name'] = $fileName;
        }
        $inputFields['company_id'] = $this->getCompanyIdAndCreateCompanyIfNeeded($inputFields['company_id']);
        $mentorProfile = new MentorProfile();
        $mentorProfile = $this->assignInputFieldsToMentorProfile($mentorProfile, $inputFields);

        DB::transaction(function() use($mentorProfile, $inputFields) {
            $newMentor = $this->mentorStorage->saveMentor($mentorProfile);
            //send welcome email to mentor, if the sign up was made through the public form
            if($inputFields['public_form'] == "true")
                $newMentor->notify(new MentorRegistered());
            $this->specialtyManager->assignSpecialtiesToMentor($newMentor, $inputFields['specialties']);
            $this->industryManager->assignIndustriesToMentor($newMentor, $inputFields['industries']);
            $this->handleMentorCompany($newMentor, $this->getCompanyIdAndCreateCompanyIfNeeded($inputFields['company_id']));
        });
    }

    /**
     * Edits (updates) a @see MentorProfile resource, identified by it's id
     *
     * @param array $inputFields the fields to assign to the mentor
     * @param $id int the id of the mentor profile
     * @param $isCvFileExistent boolean it defines whether the cv file exists or not
     */
    public function editMentor(array $inputFields, $id, $isCvFileExistent = false) {
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
        $mentor = $this->getMentor($id);
        $oldStatusId = $mentor->status_id;
        if(isset($inputFields['company_id'])) {
            $inputFields['company_id'] = $this->getCompanyIdAndCreateCompanyIfNeeded($inputFields['company_id']);
        }
        $mentor = $this->assignInputFieldsToMentorProfile($mentor, $inputFields);
        $mentorStatusHistoryManager = new MentorStatusHistoryManager();
        $loggedInUser = Auth::user();

        DB::transaction(function() use($mentor, $oldStatusId, $inputFields, $mentorStatusHistoryManager, $loggedInUser) {
            $mentor = $this->mentorStorage->saveMentor($mentor);
            if(isset($inputFields['specialties'])) {
                $this->specialtyManager->editMentorSpecialties($mentor, $inputFields['specialties']);
            }
            if(isset($inputFields['industries'])) {
                $this->industryManager->editMentorIndustries($mentor, $inputFields['industries']);
            }
            if($oldStatusId != $inputFields['status_id']) {
                $mentorStatusHistoryManager->createMentorStatusHistory($mentor, $inputFields['status_id'],
                    (isset($inputFields['status_history_comment'])) ? $inputFields['status_history_comment'] : "",
                    ($inputFields['follow_up_date'] != "") ?
                        $inputFields['follow_up_date'] : null, $loggedInUser);
            }
            if(isset($inputFields['company_id'])) {
                $this->handleMentorCompany($mentor, $inputFields['company_id']);
            }
        });
    }

    /**
     * Validates the company id passed and returns it or creates a new company if it doesn't exist in the DB
     * and returns the newly created company's id.
     *
     * @param $companyId
     * @return mixed Returns empty string when the id is invalid, the id (integer) otherwise
     */
    private function getCompanyIdAndCreateCompanyIfNeeded($companyId) {
        $companyManager = new CompanyManager();
        // check if $companyId is a valid DB id and return it
        if(intval($companyId) != 0) {
            // the id is NOT valid, return empty string
            if ($companyManager->getCompany($companyId) == null) {
                return "";
            }
            return $companyId;
        }
        // if the company doesn't exist, create a new one
        else {
            $newCompanyName = str_replace('new-company-', '', $companyId);
            $newCompany = $companyManager->createCompany(['name' => $newCompanyName]);
            return ($newCompany == null) ? "" : $newCompany->id;
        }
    }

    /**
     * Given a company id and a @see MentorProfile, if the company id is not null
     * then this company gets assigned to the mentor.
     *
     * @param MentorProfile $mentorProfile the mentor profile assign the company to
     * @param $companyId int the id of the company
     */
    private function handleMentorCompany(MentorProfile $mentorProfile, $companyId) {
        if(isset($companyId)) {
            if ($companyId == "") {
                $mentorProfile->company_id = null;
            } else {
                $mentorProfile->company_id = $companyId;
            }
        }
        $this->mentorStorage->saveMentor($mentorProfile);
    }

    /**
     * @param MentorProfile $mentorProfile the instance
     * @param array $inputFields the array of input fields
     * @return MentorProfile the instance with the fields assigned
     */
    private function assignInputFieldsToMentorProfile(MentorProfile $mentorProfile, array $inputFields) {
        $mentorProfile->fill($inputFields);
        return $mentorProfile;
    }

    /**
     * Gets a @see  MentorProfile instance, by id
     *
     * @param $id int the id
     * @return MentorProfile
     */
    public function getMentor($id) {
        $mentor = $this->mentorStorage->getMentorProfileById($id);
        return $mentor;
    }

    public function getMentorViewModel(MentorProfile $mentor) {
        $mentorViewModel = new MentorViewModel($mentor);
        return $mentorViewModel;
    }

    /**
     * Soft deletes a given @see MentorProfile, by it's id
     *
     * @param $mentorId int the id
     */
    public function deleteMentor($mentorId) {
        DB::transaction(function() use($mentorId) {
            $mentor = $this->getMentor($mentorId);
            $mentor->company_id = null;
            $this->mentorStorage->saveMentor($mentor);
            $this->mentorStorage->deleteMentor($mentor);
        });
    }

    /**
     * Gets all mentors that do not belong to any @see Company
     *
     * @return mixed Collection of mentors
     */
    public function getMentorsWithNoCompanyAssigned() {
        $mentors = $this->mentorStorage->getMentorsByCompanyId(null);
        return $mentors;
    }

    /**
     * Gets all mentors that do not belong to any @see Company, except from those
     * belonging to a given company.
     *
     * @param Company $company the company that will be excluded
     * @return Collection of mentors
     */
    public function getMentorsWithNoCompanyAssignedExceptCompany(Company $company) {
        $mentorsWithNoCompany = $this->mentorStorage->getMentorsByCompanyId(null);
        $mentorsOfThisCompany = $this->mentorStorage->getMentorsByCompanyId($company->id);
        return $mentorsOfThisCompany->merge($mentorsWithNoCompany);
    }

    /**
     * Assigns a given company to a given @see MentorProfile
     *
     * @param Company $company the company that will be assigned to the mentor
     * @param $mentorId int the id of the @see MentorProfile instance
     * @throws \Exception if the given mentor has already a company assigned to them
     */
    public function assignCompanyToMentor(Company $company, $mentorId) {
        $mentor = $this->getMentor($mentorId);
        if($mentor->hasCompany()) {
            throw new \Exception("The mentor " . $mentor->first_name . " " . $mentor->last_name . " has already a company assigned.");
        }
        $mentor->company_id = $company->id;
        $this->mentorStorage->saveMentor($mentor);
    }

    /**
     * Sets the company id of a mentor instance to be null
     *
     * @param $mentorId int the id of the @see MentorProfile instance
     */
    public function unassignCompanyFromMentor($mentorId) {
        $mentor = $this->getMentor($mentorId);
        $mentor->company_id = null;
        $this->mentorStorage->saveMentor($mentor);
    }

    /**
     * Gets mentor view models satisfying some criteria (for example
     * those who have a specific specialty and name)
     *
     * @param array $input array with criteria values
     * @return Collection|mixed|static[] a collection with mentor view models satisfying the criteria
     */
    public function getMentorViewModelsByCriteria(array $input)
    {
        $mentors = $this->getMentorsByCriteria($input);
        $mentorViewModels = new Collection();
        foreach ($mentors as $mentor) {
            $mentorViewModels->add($this->getMentorViewModel($mentor));
        }
        return $mentorViewModels;
    }

    /**
     * Gets all the filters passed and returns the filtered results
     *
     * @param $filters array with criteria values
     * @return mixed the resulted MentorProfiles or null
     * @throws \Exception
     */
    private function getMentorsByCriteria($filters) {
        if((!isset($filters['mentorName'])  || $filters['mentorName'] === "") &&
            (!isset($filters['ageRange'])  || $filters['ageRange'] === "") &&
            (!isset($filters['specialtyId'])  || $filters['specialtyId'] === "") &&
            (!isset($filters['companyId'])  || $filters['companyId'] === "") &&
            (!isset($filters['availabilityId'])  || $filters['availabilityId'] === "") &&
            (!isset($filters['residenceId'])  || $filters['residenceId'] === "") &&
            (!isset($filters['completedSessionsCount']) || $filters['completedSessionsCount'] === "") &&
            (!isset($filters['displayOnlyExternallySubscribed'])  || $filters['displayOnlyExternallySubscribed'] === 'false') &&
            (!isset($filters['displayOnlyAvailableWithCancelledSessions'])  || $filters['displayOnlyAvailableWithCancelledSessions'] === 'false')
        ) {
            return $this->mentorStorage->getAllMentorProfiles();
        }
        $whereClauseExists = false;
        $dbQuery = "select distinct mp.id 
            from mentor_profile as mp 
            left outer join mentor_specialty as ms on mp.id = ms.mentor_profile_id ";
        if(isset($filters['completedSessionsCount']) && $filters['completedSessionsCount'] != "") {
            $mentorshipSessionStatuses = new MentorshipSessionStatuses();
            $dbQuery .= "left outer join
			(
				select count(*) as completed_sessions_count, mentor_profile_id from mentorship_session
				where status_id in (" . implode(",", $mentorshipSessionStatuses::getCompletedSessionStatuses()) . ")
				group by mentor_profile_id
			) as completed_sessions on mp.id=completed_sessions.mentor_profile_id ";
        }
        if(isset($filters['displayOnlyAvailableWithCancelledSessions']) && $filters['displayOnlyAvailableWithCancelledSessions'] === "true") {
            $dbQuery .= "left outer join mentorship_session as mses on mses.mentor_profile_id = mp.id
                left outer join
                (select max(id) as last_session_id from mentorship_session group by mentor_profile_id) 
                as last_sessions on last_sessions.last_session_id = mses.id ";
        }
        $dbQuery .= "where ";
        if(isset($filters['mentorName']) && $filters['mentorName'] != "") {
            $dbQuery .= "(mp.first_name like '%" . $filters['mentorName'] . "%' or mp.last_name like '%" . $filters['mentorName'] . "%') ";
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
        if(isset($filters['specialtyId']) && $filters['specialtyId'] != "") {
            if(intval($filters['specialtyId']) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "ms.specialty_id = " . $filters['specialtyId'] . " ";
            $whereClauseExists = true;
        }
        if(isset($filters['companyId']) && $filters['companyId'] != "") {
            if(intval($filters['companyId']) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "mp.company_id = " . $filters['companyId'] . " ";
            $whereClauseExists = true;
        }
        if(isset($filters['availabilityId']) && $filters['availabilityId'] != "") {
            if(intval($filters['availabilityId']) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "mp.status_id = " . $filters['availabilityId'] . " ";
            $whereClauseExists = true;
        }
        if(isset($filters['residenceId']) && $filters['residenceId'] != "") {
            if(intval($filters['residenceId']) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "mp.residence_id = " . $filters['residenceId'] . " ";
            $whereClauseExists = true;
        }
        if(isset($filters['completedSessionsCount']) && $filters['completedSessionsCount'] != "") {
            if(intval($filters['completedSessionsCount']) == 0) {
                throw new \Exception("Filter value is not valid.");
            }
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            if($filters['completedSessionsCount'] < 5) {
                $dbQuery .= "completed_sessions.completed_sessions_count = " . $filters['completedSessionsCount'] . " ";
            } else {
                $dbQuery .= "completed_sessions.completed_sessions_count >= " . $filters['completedSessionsCount'] . " ";
            }
            $whereClauseExists = true;
        }
        if(isset($filters['displayOnlyExternallySubscribed']) && $filters['displayOnlyExternallySubscribed'] === 'true') {
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $dbQuery .= "mp.creator_user_id is null ";
            $whereClauseExists = true;
        }
        if(isset($filters['displayOnlyAvailableWithCancelledSessions']) && $filters['displayOnlyAvailableWithCancelledSessions'] === 'true') {
            if($whereClauseExists) {
                $dbQuery .= "and ";
            }
            $mentorshipSessionStatuses = new MentorshipSessionStatuses();
            $dbQuery .= "mses.status_id in (" . implode(",", $mentorshipSessionStatuses::getCancelledSessionStatuses()) . ") ";
        }
        $filteredMentorIds = RawQueriesResultsModifier::transformRawQueryStorageResultsToOneDimensionalArray(
            (new RawQueryStorage())->performRawQuery($dbQuery)
        );
        return $this->mentorStorage->getMentorsFromIdsArray($filteredMentorIds);
    }

    /**
     * Queries the mentor DB table to find string in name or email
     *
     * @param $searchQuery string the name or email that we need to check for
     * @return Collection the mentors that match
     */
    public function filterMentorsByNameAndEmail($searchQuery) {
        return $this->mentorStorage->getMentorsThatMatchGivenNameOrEmail($searchQuery);
    }

    /**
     * Change the mentor's availability status
     *
     * @param array $input parameters passed by user
     * @throws \Exception When something weird happens with the parameters passed
     */
    public function changeMentorAvailabilityStatus(array $input) {
        if(isset($input['do_not_contact'])) {
            $input['follow_up_date'] = "";
        }
        if($input['follow_up_date'] != "") {
            $dateArray = explode("/", $input['follow_up_date']);
            $input['follow_up_date'] = Carbon::createFromDate($dateArray[2], $dateArray[1], $dateArray[0]);
        }
        $mentor = $this->getMentor($input['mentor_id']);
        // if something wrong passed
        if($mentor == null || intval($input['status_id']) == 0) {
            throw new \Exception("Wrong parameters passed.");
        }
        $mentor->status_id = $input['status_id'];
        $loggedInUser = Auth::user();
        DB::transaction(function() use($mentor, $input, $loggedInUser) {
            $mentor = $this->mentorStorage->saveMentor($mentor);
            $mentorStatusHistoryManager = new MentorStatusHistoryManager();
            $mentorStatusHistoryManager->createMentorStatusHistory($mentor, $input['status_id'], $input['status_history_comment'],
                ($input['follow_up_date'] != "") ? $input['follow_up_date'] : null, $loggedInUser);
        });
    }

    /**
     * Sets mentor's status id to available
     *
     * @param $id int The mentor's id
     * @param $email string The mentor's email
     * @return bool Whether succeeded or not
     */
    public function makeMentorAvailable($id, $email) {
        $mentor = $this->getMentor($id);
        $mentorshipSessionManager = new MentorshipSessionManager();
        $currentSession = $mentorshipSessionManager->getCurrentMentorshipSessionViewModelForMentor($mentor->id);
        $mentorshipSessionStatuses = new MentorshipSessionStatuses();
        if($mentor->email === $email) {
            // mentor shouldn't be already available or participating in an active session
            if($mentor->status_id !== 1 && $currentSession != null
            && array_search($currentSession[0]->mentorshipSession->status_id,
                $mentorshipSessionStatuses::getActiveSessionStatuses()) === false
            ) {
                $this->editMentor(['status_id' => 1], $id);
                return "SUCCESS";
            }
            return "NO_PERMISSION";
        } else {
            return "NOT_FOUND";
        }
    }
}
